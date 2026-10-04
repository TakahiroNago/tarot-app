<?php

namespace App\Http\Controllers;

use App\Models\TarotReading;
use App\Models\User;
use App\Services\GuestTrialCreditService;
use App\Services\ReadingCreditService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use OpenAI\Laravel\Facades\OpenAI;
use Throwable;
use UnexpectedValueException;

class TarotController extends Controller
{
    private const GUEST_DEVICE_COOKIE = 'guest_trial_device';

    public function index(
        Request $request,
        ReadingCreditService $creditService,
        GuestTrialCreditService $guestTrialCreditService,
    ): View {
        $user = $request->user();
        $creditSummary = $user instanceof User ? $creditService->balance($user) : null;
        $guestCredits = null;
        if ($user === null) {
            $deviceToken = $this->guestDeviceToken($request);
            $guestCredits = $guestTrialCreditService->remaining($deviceToken);
        }

        return view('tarot', compact('creditSummary', 'guestCredits'));
    }

    public function draw(
        Request $request,
        ReadingCreditService $creditService,
        GuestTrialCreditService $guestTrialCreditService,
    ): View|RedirectResponse {
        $validated = $request->validate([
            'question' => [
                'required',
                'string',
                'max:200',
            ],
        ]);
        $question = $validated['question'];
        $user = $request->user();
        $creditBucket = null;
        $guestDeviceToken = null;

        if ($user instanceof User) {
            $creditBucket = $creditService->consume($user);
            if ($creditBucket === null) {
                return redirect()->route('tarot')->with('credit_exhausted', '本日利用可能なクレジットを使い切りました。クレジット購入または有料プランをご検討ください。');
            }
        } else {
            $guestDeviceToken = $this->guestDeviceToken($request);
            if (! $guestTrialCreditService->consume($guestDeviceToken)) {
                return redirect()->route('tarot')->with('credit_exhausted', '無料体験を使い切りました。会員登録すると毎日3回占えます。');
            }
        }

        $cards = [
            ['number' => '0', 'symbol' => '✧', 'name' => '愚者', 'english_name' => 'THE FOOL'],
            ['number' => 'I', 'symbol' => '☿', 'name' => '魔術師', 'english_name' => 'THE MAGICIAN'],
            ['number' => 'II', 'symbol' => '☾', 'name' => '女教皇', 'english_name' => 'THE HIGH PRIESTESS'],
            ['number' => 'III', 'symbol' => '♀', 'name' => '女帝', 'english_name' => 'THE EMPRESS'],
            ['number' => 'IV', 'symbol' => '♈︎', 'name' => '皇帝', 'english_name' => 'THE EMPEROR'],
            ['number' => 'V', 'symbol' => '✥', 'name' => '教皇', 'english_name' => 'THE HIEROPHANT'],
            ['number' => 'VI', 'symbol' => '♡', 'name' => '恋人たち', 'english_name' => 'THE LOVERS'],
            ['number' => 'VII', 'symbol' => '⚔', 'name' => '戦車', 'english_name' => 'THE CHARIOT'],
            ['number' => 'VIII', 'symbol' => '♌︎', 'name' => '力', 'english_name' => 'STRENGTH'],
            ['number' => 'IX', 'symbol' => '✶', 'name' => '隠者', 'english_name' => 'THE HERMIT'],
            ['number' => 'X', 'symbol' => '⟳', 'name' => '運命の輪', 'english_name' => 'WHEEL OF FORTUNE'],
            ['number' => 'XI', 'symbol' => '⚖', 'name' => '正義', 'english_name' => 'JUSTICE'],
            ['number' => 'XII', 'symbol' => '⤵', 'name' => '吊るされた男', 'english_name' => 'THE HANGED MAN'],
            ['number' => 'XIII', 'symbol' => '♻︎', 'name' => '死神', 'english_name' => 'DEATH'],
            ['number' => 'XIV', 'symbol' => '⚗', 'name' => '節制', 'english_name' => 'TEMPERANCE'],
            ['number' => 'XV', 'symbol' => '♑︎', 'name' => '悪魔', 'english_name' => 'THE DEVIL'],
            ['number' => 'XVI', 'symbol' => '⚡︎', 'name' => '塔', 'english_name' => 'THE TOWER'],
            ['number' => 'XVII', 'symbol' => '✷', 'name' => '星', 'english_name' => 'THE STAR'],
            ['number' => 'XVIII', 'symbol' => '☽', 'name' => '月', 'english_name' => 'THE MOON'],
            ['number' => 'XIX', 'symbol' => '☼', 'name' => '太陽', 'english_name' => 'THE SUN'],
            ['number' => 'XX', 'symbol' => '🎺︎', 'name' => '審判', 'english_name' => 'JUDGEMENT'],
            ['number' => 'XXI', 'symbol' => '◎', 'name' => '世界', 'english_name' => 'THE WORLD'],
        ];

        $selectedCard = $cards[array_rand($cards)];

        try {
            $isPremiumMember = $user instanceof User && $user->plan === User::PLAN_PREMIUM;
            $category = null;
            $history = collect();

            if ($isPremiumMember) {
                $category = $this->readingCategory($question);
                $history = $user->tarotReadings()
                    ->where('category', $category)
                    ->whereNotNull('summary')
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->limit(3)
                    ->get(['summary', 'created_at']);
            }

            $response = OpenAI::chat()->create([
                'model' => 'gpt-5.6-luna',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => '[REDACTED]',
                    ],
                    [
                        'role' => 'user',
                        'content' => $isPremiumMember
                            ? $this->premiumReadingPrompt($question, $selectedCard['name'], $history)
                            : "質問：{$question}\nカード：{$selectedCard['name']}",
                    ],
                ],
            ]);

            $interpretation = $response->choices[0]->message->content;
            $metadata = $isPremiumMember
                ? [
                    'category' => $category,
                    'summary' => $this->readingSummary($question, $selectedCard['name'], $interpretation),
                ]
                : $this->readingMetadata($question, $selectedCard['name'], $interpretation);
            $reading = [
                'question' => $question,
                'card' => $selectedCard['name'],
                'interpretation' => $interpretation,
                ...$metadata,
            ];

            if ($user instanceof User) {
                $creditSummary = $creditService->balance($user);
                $creditsRemaining = $creditSummary['total'];
                $user->tarotReadings()->create($reading);
            } else {
                $creditsRemaining = $guestTrialCreditService->remaining($guestDeviceToken);
                $sessionReadings = $request->session()->get('tarot_readings', []);
                $sessionReadings[] = $reading;
                $request->session()->put('tarot_readings', $sessionReadings);
            }
        } catch (Throwable $exception) {
            if ($user instanceof User && $creditBucket !== null) {
                $creditService->refund($user, $creditBucket);
            } elseif ($guestDeviceToken !== null) {
                $guestTrialCreditService->refund($guestDeviceToken);
            }

            throw $exception;
        }

        return view('tarot-result', [
            ...$reading,
            'creditsRemaining' => $creditsRemaining,
            'isGuest' => ! $user instanceof User,
            'cardNumber' => $selectedCard['number'],
            'cardSymbol' => $selectedCard['symbol'],
            'cardEnglishName' => $selectedCard['english_name'],
        ]);
    }

    private function readingCategory(string $question): string
    {
        $response = OpenAI::chat()->create([
            'model' => 'gpt-5.6-luna',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => '[REDACTED]',
                ],
                [
                    'role' => 'user',
                    'content' => $question,
                ],
            ],
        ]);

        $category = $response->choices[0]->message->content;
        if (! is_string($category) || ! in_array(trim($category), TarotReading::CATEGORIES, true)) {
            throw new UnexpectedValueException('OpenAI returned an invalid reading category.');
        }

        return trim($category);
    }

    /**
     * @param  Collection<int, TarotReading>  $history
     */
    private function premiumReadingPrompt(string $question, string $card, Collection $history): string
    {
        $historyContext = $history->isEmpty()
            ? '該当カテゴリの過去のリーディング履歴はありません。'
            : $history
                ->map(fn (TarotReading $reading): string => "- {$reading->created_at->format('Y/m/d H:i')}：{$reading->summary}")
                ->implode("\n");

        return "過去の同カテゴリのリーディング要約と日時：\n{$historyContext}\n\n今回の質問：{$question}\nカード：{$card}";
    }

    private function readingSummary(string $question, string $card, string $interpretation): string
    {
        $response = OpenAI::chat()->create([
            'model' => 'gpt-5.6-luna',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => '[REDACTED]',
                ],
                [
                    'role' => 'user',
                    'content' => "質問：{$question}\nカード：{$card}\nリーディング結果：{$interpretation}",
                ],
            ],
            'response_format' => ['type' => 'json_object'],
        ]);

        $content = $response->choices[0]->message->content;
        if (! is_string($content)) {
            throw new UnexpectedValueException('OpenAI returned no reading summary.');
        }

        $result = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $summary = is_array($result) ? ($result['summary'] ?? null) : null;
        if (
            ! is_string($summary)
            || trim($summary) === ''
            || mb_strlen($summary) > TarotReading::SUMMARY_MAX_LENGTH
        ) {
            throw new UnexpectedValueException('OpenAI returned an invalid reading summary.');
        }

        return $summary;
    }

    /**
     * @return array{category: string, summary: string}
     */
    private function readingMetadata(string $question, string $card, string $interpretation): array
    {
        $response = OpenAI::chat()->create([
            'model' => 'gpt-5.6-luna',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => '[REDACTED]',
                ],
                [
                    'role' => 'user',
                    'content' => "質問：{$question}\nカード：{$card}\nリーディング結果：{$interpretation}",
                ],
            ],
            'response_format' => ['type' => 'json_object'],
        ]);

        $content = $response->choices[0]->message->content;
        if (! is_string($content)) {
            throw new UnexpectedValueException('OpenAI returned no reading metadata.');
        }

        $metadata = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $category = is_array($metadata) ? ($metadata['category'] ?? null) : null;
        $summary = is_array($metadata) ? ($metadata['summary'] ?? null) : null;

        if (
            ! is_string($category)
            || ! in_array($category, TarotReading::CATEGORIES, true)
            || ! is_string($summary)
            || trim($summary) === ''
            || mb_strlen($summary) > TarotReading::SUMMARY_MAX_LENGTH
        ) {
            throw new UnexpectedValueException('OpenAI returned invalid reading metadata.');
        }

        return [
            'category' => $category,
            'summary' => $summary,
        ];
    }

    private function guestDeviceToken(Request $request): string
    {
        $token = $request->cookie(self::GUEST_DEVICE_COOKIE);

        if (! is_string($token) || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            $token = bin2hex(random_bytes(32));
            Cookie::queue(cookie(
                self::GUEST_DEVICE_COOKIE,
                $token,
                60 * 24 * 365 * 5,
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                'lax',
            ));
        }

        return $token;
    }
}
