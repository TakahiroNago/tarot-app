<?php

namespace App\Http\Controllers;

use App\Models\TarotReading;
use App\Models\User;
use App\Support\TarotCardCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class ReadingHistoryController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $selectedCategory = $request->validate([
            'category' => ['nullable', 'string', Rule::in(TarotReading::CATEGORIES)],
        ])['category'] ?? null;
        $user = $request->user();
        if ($user instanceof User && ! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        $databaseReadings = null;
        $historyUpgradePrompt = false;
        $databaseCategories = collect();

        if ($user instanceof User) {
            $historyQuery = $user->tarotReadings()
                ->orderByDesc('created_at')
                ->orderByDesc('id');
            if ($user->plan !== User::PLAN_PREMIUM) {
                $historyQuery->where('created_at', '>=', now()->subMonths(3));
            }

            $historyCount = $historyQuery->count();
            $displayLimit = $user->plan === User::PLAN_FREE ? min(50, $historyCount) : $historyCount;
            $historyUpgradePrompt = $user->plan === User::PLAN_FREE && $historyCount > 50;
            $visibleReadingIds = null;
            if ($user->plan === User::PLAN_FREE) {
                $visibleReadingIds = (clone $historyQuery)
                    ->select('id')
                    ->limit($displayLimit)
                    ->pluck('id')
                    ->all();
            }

            $categoryQuery = clone $historyQuery;
            if ($visibleReadingIds !== null) {
                $categoryQuery->whereIn('id', $visibleReadingIds);
            }
            $databaseCategories = $categoryQuery
                ->reorder()
                ->whereNotNull('category')
                ->distinct()
                ->pluck('category');

            if ($selectedCategory !== null) {
                $filteredHistoryQuery = clone $historyQuery;
                if ($visibleReadingIds !== null) {
                    $filteredHistoryQuery->whereIn('id', $visibleReadingIds);
                }

                $historyQuery = $filteredHistoryQuery->where('category', $selectedCategory);
                $displayLimit = $historyQuery->count();
            }

            $perPage = 20;
            $page = LengthAwarePaginator::resolveCurrentPage();
            $databaseReadings = new LengthAwarePaginator(
                $historyQuery
                    ->offset(($page - 1) * $perPage)
                    ->limit(min($perPage, max(0, $displayLimit - (($page - 1) * $perPage))))
                    ->get(),
                $displayLimit,
                $perPage,
                $page,
                ['path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => $request->query()],
            );
        }

        $sessionReadings = $this->sessionReadings($request, $selectedCategory);

        $sessionCategories = collect($request->session()->get('tarot_readings', []))
            ->filter(fn (mixed $reading): bool => $this->isReading($reading)
                && in_array($reading['category'] ?? null, TarotReading::CATEGORIES, true))
            ->pluck('category');
        $categories = collect(TarotReading::CATEGORIES)
            ->filter(fn (string $category): bool => $databaseCategories->contains($category)
                || $sessionCategories->contains($category))
            ->values();

        return view('readings.index', compact(
            'categories',
            'databaseReadings',
            'historyUpgradePrompt',
            'selectedCategory',
            'sessionReadings',
        ));
    }

    public function show(Request $request, string $reading): View|RedirectResponse
    {
        if (preg_match('/^session-(\d+)$/', $reading, $matches) === 1) {
            $sessionReading = $request->session()->get('tarot_readings.'.$matches[1]);
            abort_unless($this->isReading($sessionReading), 404);

            $readingData = [
                'question' => $sessionReading['question'],
                'card' => $sessionReading['card'] ?? null,
                'interpretation' => $sessionReading['interpretation'],
            ];
        } else {
            $user = $request->user();

            if (! $user instanceof User) {
                return redirect()->route('login');
            }

            if (! $user->hasVerifiedEmail()) {
                return redirect()->route('verification.notice');
            }

            $readings = $user->tarotReadings();
            if ($user->plan !== User::PLAN_PREMIUM) {
                $readings->where('created_at', '>=', now()->subMonths(3));
            }

            $savedReading = $readings->findOrFail($reading);
            if ($user->plan === User::PLAN_FREE) {
                $newerReadings = $user->tarotReadings()
                    ->where(function ($query) use ($savedReading): void {
                        $query->where('created_at', '>', $savedReading->created_at)
                            ->orWhere(function ($query) use ($savedReading): void {
                                $query->where('created_at', $savedReading->created_at)
                                    ->where('id', '>', $savedReading->id);
                            });
                    })
                    ->count();

                abort_if($newerReadings >= 50, 404);
            }

            $readingData = $savedReading->only(['question', 'card', 'interpretation', 'created_at']);
        }

        $card = is_string($readingData['card'] ?? null)
            ? TarotCardCatalog::find($readingData['card'])
            : null;

        return view('readings.show', [
            'reading' => $readingData,
            'cardNumber' => $card['number'] ?? '',
            'cardSymbol' => $card['symbol'] ?? '✧',
            'cardEnglishName' => $card['english_name'] ?? $readingData['card'],
        ]);
    }

    /** @return Collection<int, array{id: string, question: string, card: string|null, interpretation: string}> */
    private function sessionReadings(Request $request, ?string $selectedCategory): Collection
    {
        return collect($request->session()->get('tarot_readings', []))
            ->filter(fn (mixed $reading): bool => $this->isReading($reading)
                && ($selectedCategory === null || ($reading['category'] ?? null) === $selectedCategory))
            ->map(fn (array $reading, int|string $index): array => [
                'id' => 'session-'.$index,
                'question' => $reading['question'],
                'card' => $reading['card'] ?? null,
                'interpretation' => $reading['interpretation'],
            ])
            ->reverse()
            ->values();
    }

    private function isReading(mixed $reading): bool
    {
        return is_array($reading)
            && is_string($reading['question'] ?? null)
            && (
                ! array_key_exists('card', $reading)
                || $reading['card'] === null
                || is_string($reading['card'])
            )
            && is_string($reading['interpretation'] ?? null);
    }
}
