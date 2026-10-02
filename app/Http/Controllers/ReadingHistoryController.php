<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ReadingHistoryController extends Controller
{
    public function index(Request $request): View
    {
        $databaseReadings = $request->user()?->tarotReadings()->latest()->paginate(15);
        $sessionReadings = $this->sessionReadings($request);

        return view('readings.index', compact('databaseReadings', 'sessionReadings'));
    }

    public function show(Request $request, string $reading): View|RedirectResponse
    {
        if (preg_match('/^session-(\d+)$/', $reading, $matches) === 1) {
            $sessionReading = $request->session()->get('tarot_readings.'.$matches[1]);
            abort_unless($this->isReading($sessionReading), 404);

            $readingData = [
                'question' => $sessionReading['question'],
                'card' => $sessionReading['card'],
                'interpretation' => $sessionReading['interpretation'],
            ];
        } else {
            $user = $request->user();

            if (! $user instanceof User) {
                return redirect()->route('login');
            }

            $savedReading = $user->tarotReadings()->findOrFail($reading);
            $readingData = $savedReading->only(['question', 'card', 'interpretation', 'created_at']);
        }

        return view('readings.show', ['reading' => $readingData]);
    }

    public function save(Request $request, string $reading): RedirectResponse
    {
        if (preg_match('/^session-(\d+)$/', $reading, $matches) !== 1) {
            abort(404);
        }

        $sessionReadings = $request->session()->get('tarot_readings', []);
        $sessionReading = $sessionReadings[$matches[1]] ?? null;
        abort_unless($this->isReading($sessionReading), 404);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $user->tarotReadings()->create([
            'question' => $sessionReading['question'],
            'card' => $sessionReading['card'],
            'interpretation' => $sessionReading['interpretation'],
        ]);

        unset($sessionReadings[$matches[1]]);
        $request->session()->put('tarot_readings', $sessionReadings);

        return redirect()->route('readings.index');
    }

    /** @return Collection<int, array{id: string, question: string, card: string, interpretation: string}> */
    private function sessionReadings(Request $request): Collection
    {
        return collect($request->session()->get('tarot_readings', []))
            ->filter(fn (mixed $reading): bool => $this->isReading($reading))
            ->map(fn (array $reading, int|string $index): array => [
                'id' => 'session-'.$index,
                'question' => $reading['question'],
                'card' => $reading['card'],
                'interpretation' => $reading['interpretation'],
            ])
            ->reverse()
            ->values();
    }

    private function isReading(mixed $reading): bool
    {
        return is_array($reading)
            && isset($reading['question'], $reading['card'], $reading['interpretation']);
    }
}
