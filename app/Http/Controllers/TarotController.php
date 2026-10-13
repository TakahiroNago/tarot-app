<?php

namespace App\Http\Controllers;

use App\Models\TarotReading;
use App\Models\User;
use App\Services\GuestTrialCreditService;
use App\Services\PublicReadingShareService;
use App\Services\ReadingCreditService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use OpenAI\Laravel\Facades\OpenAI;
use Throwable;
use UnexpectedValueException;

class TarotController extends Controller
{
    private const GUEST_DEVICE_COOKIE = 'guest_trial_device';

    private const PREMIUM_DAILY_LIMIT = 1000;

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
                '別の相談に回答中です。完了後にもう一度お試しください。',
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
                $shareService,
            );
        } finally {
            $lock->release();
        }
    }

    public function daily(
        Request $request,
        ReadingCreditService $creditService,
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
                '別の相談に回答中です。完了後にもう一度お試しください。',
            );
        }

        try {
            $readingDate = now()->toDateString();
            $savedReading = $user->tarotReadings()
                ->whereDate('daily_reading_date', $readingDate)
                ->first();

            if ($savedReading === null) {
                $reading = $this->createDailyReading('今日のあなたに向けた禅のメッセージをお願いします');
                $savedReading = $user->tarotReadings()->create([
                    ...$reading,
                    'daily_reading_date' => $readingDate,
                ]);
            }

            $reading = $savedReading->only([
                'question',
                'card',
                'interpretation',
                'category',
                'summary',
            ]);
            $shareToken = $shareService->create($reading);
            $creditSummary = $creditService->balance($user);

            return view('tarot-result', [
                ...$reading,
                'shareUrl' => route('readings.share', ['token' => $shareToken]),
                'creditsRemaining' => $creditSummary['total'],
                'isGuest' => false,
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
        PublicReadingShareService $shareService,
    ): View|RedirectResponse {
        $creditBucket = null;

        if ($user instanceof User) {
            if ($user->plan === User::PLAN_PREMIUM) {
                $dailyRateLimitKey = 'premium-daily-reading:'.$user->getAuthIdentifier().':'.now()->toDateString();
                if (RateLimiter::tooManyAttempts($dailyRateLimitKey, self::PREMIUM_DAILY_LIMIT)) {
                    return redirect()->route('tarot')->with(
                        'reading_rate_limited',
                        '本日のプレミアムプランの利用上限に達しました。明日以降に再度お試しください。',
                    );
                }

                $rateLimitKey = 'premium-reading:'.$user->getAuthIdentifier();
                if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
                    $secondsUntilAvailable = RateLimiter::availableIn($rateLimitKey);

                    return redirect()->route('tarot')->with(
                        'reading_rate_limited',
                        "プレミアムプランの相談は1分あたり5回までです。{$secondsUntilAvailable}秒後に再度お試しください。",
                    );
                }

                RateLimiter::hit($dailyRateLimitKey, 86400);
                RateLimiter::hit($rateLimitKey, 60);
            }

            $creditBucket = $creditService->consume($user);
            if ($creditBucket === null) {
                return redirect()->route('tarot')->with('credit_exhausted', '本日利用可能なクレジットを使い切りました。クレジット購入または有料プランをご検討ください。');
            }
        } else {
            if (! is_string($guestDeviceToken)) {
                throw new \LogicException('Guest device token is required for guest readings.');
            }

            if (! $guestTrialCreditService->consume($guestDeviceToken)) {
                return redirect()->route('tarot')->with('credit_exhausted', '無料体験を使い切りました。会員登録すると毎日3回相談できます。');
            }
        }

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
                        'content' => $this->zenTeacherInstructions().($isPremiumMember
                            ? '提示された過去の同カテゴリの相談要約と日時を踏まえて今回の悩みに答えますが、過去の要約にない事実は推測しません。'
                            : ''),
                    ],
                    [
                        'role' => 'user',
                        'content' => $isPremiumMember
                            ? $this->premiumReadingPrompt($question, $history)
                            : "相談：{$question}",
                    ],
                ],
            ]);

            $interpretation = $response->choices[0]->message->content;
            if (! is_string($interpretation) || trim($interpretation) === '') {
                throw new UnexpectedValueException('OpenAI returned no consultation response.');
            }

            $metadata = $isPremiumMember
                ? [
                    'category' => $category,
                    'summary' => $this->readingSummary($question, $interpretation),
                ]
                : $this->readingMetadata($question, $interpretation);
            $reading = [
                'question' => $question,
                'card' => null,
                'interpretation' => $interpretation,
                ...$metadata,
            ];
            $shareToken = $shareService->create($reading);

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
            'shareUrl' => route('readings.share', ['token' => $shareToken]),
            'creditsRemaining' => $creditsRemaining,
            'isGuest' => ! $user instanceof User,
        ]);
    }

    /**
     * @return array{question: string, interpretation: string, category: string, summary: string}
     */
    private function createDailyReading(string $question): array
    {
        $response = OpenAI::chat()->create([
            'model' => 'gpt-5.6-luna',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->zenTeacherInstructions().'今日できる小さな実践を一つ含むメッセージを作成してください。interpretation にメッセージ、category に「仕事」「恋愛」「お金」「家族」「人間関係」「健康」「将来」「自分自身」「その他」のいずれか、summary にメッセージの150文字以内の要約を設定し、JSONオブジェクトで返してください。',
                ],
                [
                    'role' => 'user',
                    'content' => "相談：{$question}",
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
            'interpretation' => $interpretation,
            'category' => $category,
            'summary' => $summary,
        ];
    }

    private function readingCategory(string $question): string
    {
        $response = OpenAI::chat()->create([
            'model' => 'gpt-5.6-luna',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => '相談文を「仕事」「恋愛」「お金」「家族」「人間関係」「健康」「将来」「自分自身」「その他」のいずれか1つに分類してください。分類結果のカテゴリ名だけを返してください。',
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
    private function premiumReadingPrompt(string $question, Collection $history): string
    {
        $historyContext = $history->isEmpty()
            ? '該当カテゴリの過去の相談記録はありません。'
            : $history
                ->map(fn (TarotReading $reading): string => "- {$reading->created_at->format('Y/m/d H:i')}：{$reading->summary}")
                ->implode("\n");

        return "過去の同カテゴリの相談要約と日時：\n{$historyContext}\n\n今回の相談：{$question}";
    }

    private function zenTeacherInstructions(): string
    {
        return 'あなたは慈悲心と智慧を備えた仏教禅師のように、相談者の悩みに日本語で答えます。悩みを否定せずに受け止め、無常・執着・今ここなどの視点を押しつけにならない平易な言葉で示し、相談者が選べる現実的な小さな一歩や内省の問いを添えてください。未来を断定したり、病気・法律・金銭などの専門判断をしたりせず、必要な場合は適切な専門家への相談を勧めてください。自傷他害や差し迫った危険がうかがわれる場合は、禅の教えだけで済ませず、身近な信頼できる人や地域の緊急支援窓口にすぐつながるよう促してください。';
    }

    private function readingSummary(string $question, string $interpretation): string
    {
        $response = OpenAI::chat()->create([
            'model' => 'gpt-5.6-luna',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => '相談への禅的な回答を日本語で150文字以内に要約してください。相談者が考えたり行動したりする助けとなる要点をまとめ、JSONオブジェクトで summary の1項目だけを返してください。',
                ],
                [
                    'role' => 'user',
                    'content' => "相談：{$question}\n回答：{$interpretation}",
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
    private function readingMetadata(string $question, string $interpretation): array
    {
        $response = OpenAI::chat()->create([
            'model' => 'gpt-5.6-luna',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'あなたは相談への禅的な回答の分類・要約を行います。カテゴリは「仕事」「恋愛」「お金」「家族」「人間関係」「健康」「将来」「自分自身」「その他」の9つから相談内容に最も関連のあるものを1つだけ選んでください。要約は日本語で150文字以内にしてください。JSONオブジェクトで category と summary の2項目のみを返してください。',
                ],
                [
                    'role' => 'user',
                    'content' => "相談：{$question}\n回答：{$interpretation}",
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
