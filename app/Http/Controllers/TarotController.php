<?php

namespace App\Http\Controllers;

use App\Models\TarotReading;
use App\Models\User;
use App\Services\GuestTrialCreditService;
use App\Services\ProductRecommendationService;
use App\Services\PublicReadingShareService;
use App\Services\ReadingCreditService;
use App\Support\TarotCardCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use OpenAI\Laravel\Facades\OpenAI;
use Throwable;
use UnexpectedValueException;

class TarotController extends Controller
{
    private const GUEST_DEVICE_COOKIE = 'guest_trial_device';

    private const DRAW_LOCK_TTL_SECONDS = 300;

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
        ProductRecommendationService $productRecommendationService,
        PublicReadingShareService $shareService,
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
        $guestDeviceToken = $user instanceof User ? null : $this->guestDeviceToken($request);
        $lockIdentity = $user instanceof User
            ? 'user:'.$user->getAuthIdentifier()
            : 'guest:'.hash('sha256', $guestDeviceToken);
        $lock = Cache::lock('tarot-reading:'.$lockIdentity, self::DRAW_LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            return redirect()->route('tarot')->with(
                'reading_rate_limited',
                '別の占いを実行中です。完了後にもう一度お試しください。',
            );
        }

        try {
            return $this->drawWithLock(
                $request,
                $question,
                $user instanceof User ? $user : null,
                $guestDeviceToken,
                $creditService,
                $guestTrialCreditService,
                $productRecommendationService,
                $shareService,
            );
        } finally {
            $lock->release();
        }
    }

    public function daily(
        Request $request,
        ReadingCreditService $creditService,
        ProductRecommendationService $productRecommendationService,
        PublicReadingShareService $shareService,
    ): View|RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $lock = Cache::lock(
            'tarot-reading:user:'.$user->getAuthIdentifier(),
            self::DRAW_LOCK_TTL_SECONDS,
        );
        if (! $lock->get()) {
            return redirect()->route('home')->with(
                'daily_reading_rate_limited',
                '別の占いを実行中です。完了後にもう一度お試しください。',
            );
        }

        try {
            $readingDate = now()->toDateString();
            $savedReading = $user->tarotReadings()
                ->whereDate('daily_reading_date', $readingDate)
                ->first();

            if ($savedReading === null) {
                $cards = TarotCardCatalog::all();
                $selectedCard = $cards[array_rand($cards)];
                $reading = $this->createDailyReading(
                    '今日のメッセージをお願いします',
                    $selectedCard['name'],
                );
                $savedReading = $user->tarotReadings()->create([
                    ...$reading,
                    'daily_reading_date' => $readingDate,
                ]);
            }

            $card = TarotCardCatalog::find($savedReading->card);
            if ($card === null) {
                throw new UnexpectedValueException('Stored daily reading has an unknown tarot card.');
            }

            $reading = $savedReading->only([
                'question',
                'card',
                'interpretation',
                'category',
                'summary',
            ]);
            $recommendedProduct = $productRecommendationService->forReading($savedReading);
            $reading['recommended_product_id'] = $recommendedProduct?->id;
            $shareToken = $shareService->create($reading);
            $creditSummary = $creditService->balance($user);

            return view('tarot-result', [
                ...$reading,
                'shareUrl' => route('readings.share', ['token' => $shareToken]),
                'creditsRemaining' => $creditSummary['total'],
                'isGuest' => false,
                'cardNumber' => $card['number'],
                'cardSymbol' => $card['symbol'],
                'cardEnglishName' => $card['english_name'],
                'recommendedProduct' => $recommendedProduct,
            ]);
        } finally {
            $lock->release();
        }
    }

    private function drawWithLock(
        Request $request,
        string $question,
        ?User $user,
        ?string $guestDeviceToken,
        ReadingCreditService $creditService,
        GuestTrialCreditService $guestTrialCreditService,
        ProductRecommendationService $productRecommendationService,
        PublicReadingShareService $shareService,
    ): View|RedirectResponse {
        $creditBucket = null;

        if ($user instanceof User) {
            $creditBucket = $creditService->consume($user);
            if ($creditBucket === null) {
                return redirect()->route('tarot')->with('credit_exhausted', '本日利用可能なクレジットを使い切りました。明日以降に再度お試しください。');
            }
        } else {
            if (! is_string($guestDeviceToken)) {
                throw new \LogicException('Guest device token is required for guest readings.');
            }

            if (! $guestTrialCreditService->consume($guestDeviceToken)) {
                return redirect()->route('tarot')->with('credit_exhausted', '無料体験を使い切りました。会員登録すると毎日3回占えます。');
            }
        }

        $cards = TarotCardCatalog::all();
        $selectedCard = $cards[array_rand($cards)];

        try {
            $response = OpenAI::chat()->create([
                'model' => 'gpt-5.6-luna',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'あなたはタロット占い師です。カードの象徴をもとに、相談者に分かりやすく占い結果を説明してください。',
                    ],
                    [
                        'role' => 'user',
                        'content' => "質問：{$question}\nカード：{$selectedCard['name']}",
                    ],
                ],
            ]);

            $interpretation = $response->choices[0]->message->content;
            $metadata = $this->readingMetadata($question, $selectedCard['name'], $interpretation);
            $reading = [
                'question' => $question,
                'card' => $selectedCard['name'],
                'interpretation' => $interpretation,
                ...$metadata,
            ];

            if ($user instanceof User) {
                $creditSummary = $creditService->balance($user);
                $creditsRemaining = $creditSummary['total'];
                $savedReading = $user->tarotReadings()->create($reading);
                $recommendedProduct = $productRecommendationService->forReading($savedReading);
                $reading['recommended_product_id'] = $recommendedProduct?->id;
            } else {
                $creditsRemaining = $guestTrialCreditService->remaining($guestDeviceToken);
                $recommendedProduct = $productRecommendationService->forSession($reading);
                $sessionReadings = $request->session()->get('tarot_readings', []);
                $sessionReadings[] = $reading;
                $request->session()->put('tarot_readings', $sessionReadings);
            }
            $shareToken = $shareService->create($reading);
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
            'shareUrl' => route('readings.share', ['token' => $shareToken]),
            'creditsRemaining' => $creditsRemaining,
            'isGuest' => ! $user instanceof User,
            'cardNumber' => $selectedCard['number'],
            'cardSymbol' => $selectedCard['symbol'],
            'cardEnglishName' => $selectedCard['english_name'],
            'recommendedProduct' => $recommendedProduct,
        ]);
    }

    /**
     * @return array{question: string, card: string, interpretation: string, category: string, summary: string}
     */
    private function createDailyReading(string $question, string $card): array
    {
        $response = OpenAI::chat()->create([
            'model' => 'gpt-5.6-luna',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'あなたはタロット占い師です。カードの象徴をもとに、今日の相談者に分かりやすいメッセージを日本語で作成してください。interpretation に占い結果、category に「仕事」「恋愛」「お金」「家族」「人間関係」「健康」「将来」「自分自身」「その他」のいずれか、summary に占い結果の150文字以内の要約を設定し、JSONオブジェクトで返してください。',
                ],
                [
                    'role' => 'user',
                    'content' => "質問：{$question}\nカード：{$card}",
                ],
            ],
            'response_format' => ['type' => 'json_object'],
        ]);

        $content = $response->choices[0]->message->content;
        if (! is_string($content)) {
            throw new UnexpectedValueException('OpenAI returned no daily reading.');
        }

        $result = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $interpretation = is_array($result) ? ($result['interpretation'] ?? null) : null;
        $category = is_array($result) ? ($result['category'] ?? null) : null;
        $summary = is_array($result) ? ($result['summary'] ?? null) : null;
        if (
            ! is_string($interpretation)
            || trim($interpretation) === ''
            || ! is_string($category)
            || ! in_array($category, TarotReading::CATEGORIES, true)
            || ! is_string($summary)
            || trim($summary) === ''
            || mb_strlen($summary) > TarotReading::SUMMARY_MAX_LENGTH
        ) {
            throw new UnexpectedValueException('OpenAI returned invalid daily reading data.');
        }

        return [
            'question' => '今日のメッセージ',
            'card' => $card,
            'interpretation' => $interpretation,
            'category' => $category,
            'summary' => $summary,
        ];
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
                    'content' => 'あなたはタロットリーディング結果の分類・要約を行います。カテゴリは「仕事」「恋愛」「お金」「家族」「人間関係」「健康」「将来」「自分自身」「その他」の9つから最も関連のあるものを1つだけ選んでください。要約は日本語で150文字以内にしてください。JSONオブジェクトで category と summary の2項目のみを返してください。',
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
