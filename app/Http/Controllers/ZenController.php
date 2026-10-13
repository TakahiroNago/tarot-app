<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\ZenConsultation;
use App\Services\ConsultationCreditService;
use App\Services\GuestTrialCreditService;
use App\Services\PublicConsultationShareService;
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

class ZenController extends Controller
{
    private const GUEST_DEVICE_COOKIE = 'guest_trial_device';

    private const PREMIUM_DAILY_LIMIT = 1000;

    private const RESPONSE_LOCK_TTL_SECONDS = 300;

    public function index(
        Request $request,
        ConsultationCreditService $creditService,
        GuestTrialCreditService $guestTrialCreditService,
    ): View {
        $user = $request->user();
        $creditSummary = $user instanceof User ? $creditService->balance($user) : null;
        $guestCredits = null;
        if ($user === null) {
            $deviceToken = $this->guestDeviceToken($request);
            $guestCredits = $guestTrialCreditService->remaining($deviceToken);
        }

        return view('zen', compact('creditSummary', 'guestCredits'));
    }

    public function respond(
        Request $request,
        ConsultationCreditService $creditService,
        GuestTrialCreditService $guestTrialCreditService,
        PublicConsultationShareService $shareService,
    ): View|RedirectResponse {
        $validated = $request->validate([
            'concern' => [
                'required',
                'string',
                'max:200',
            ],
        ]);
        $concern = $validated['concern'];
        $user = $request->user();
        $guestDeviceToken = $user instanceof User ? null : $this->guestDeviceToken($request);
        $lockIdentity = $user instanceof User
            ? 'user:'.$user->getAuthIdentifier()
            : 'guest:'.hash('sha256', $guestDeviceToken);
        $lock = Cache::lock('zen-consultation:'.$lockIdentity, self::RESPONSE_LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            return redirect()->route('zen')->with(
                'consultation_rate_limited',
                '別の相談に回答中です。完了後にもう一度お試しください。',
            );
        }

        try {
            return $this->respondWithLock(
                $request,
                $concern,
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
        ConsultationCreditService $creditService,
        PublicConsultationShareService $shareService,
    ): View|RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $lock = Cache::lock(
            'zen-consultation:user:'.$user->getAuthIdentifier(),
            self::RESPONSE_LOCK_TTL_SECONDS,
        );
        if (! $lock->get()) {
            return redirect()->route('home')->with(
                'daily_zen_message_rate_limited',
                '別の相談に回答中です。完了後にもう一度お試しください。',
            );
        }

        try {
            $consultationDate = now()->toDateString();
            $savedConsultation = $user->zenConsultations()
                ->whereDate('daily_consultation_date', $consultationDate)
                ->first();

            if ($savedConsultation === null) {
                $consultation = $this->createDailyConsultation('今日のあなたに向けた禅のメッセージをお願いします');
                $savedConsultation = $user->zenConsultations()->create([
                    ...$consultation,
                    'daily_consultation_date' => $consultationDate,
                ]);
            }

            $consultation = $savedConsultation->only([
                'concern',
                'response',
                'topic',
                'summary',
            ]);
            $shareToken = $shareService->create($consultation);
            $creditSummary = $creditService->balance($user);

            return view('zen-result', [
                ...$consultation,
                'shareUrl' => route('consultations.share', ['token' => $shareToken]),
                'creditsRemaining' => $creditSummary['total'],
                'isGuest' => false,
            ]);
        } finally {
            $lock->release();
        }
    }

    private function respondWithLock(
        Request $request,
        string $concern,
        ?User $user,
        ?string $guestDeviceToken,
        ConsultationCreditService $creditService,
        GuestTrialCreditService $guestTrialCreditService,
        PublicConsultationShareService $shareService,
    ): View|RedirectResponse {
        $creditBucket = null;

        if ($user instanceof User) {
            if ($user->plan === User::PLAN_PREMIUM) {
                $dailyRateLimitKey = 'premium-daily-consultation:'.$user->getAuthIdentifier().':'.now()->toDateString();
                if (RateLimiter::tooManyAttempts($dailyRateLimitKey, self::PREMIUM_DAILY_LIMIT)) {
                    return redirect()->route('zen')->with(
                        'consultation_rate_limited',
                        '本日のプレミアムプランの利用上限に達しました。明日以降に再度お試しください。',
                    );
                }

                $rateLimitKey = 'premium-consultation:'.$user->getAuthIdentifier();
                if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
                    $secondsUntilAvailable = RateLimiter::availableIn($rateLimitKey);

                    return redirect()->route('zen')->with(
                        'consultation_rate_limited',
                        "プレミアムプランの相談は1分あたり5回までです。{$secondsUntilAvailable}秒後に再度お試しください。",
                    );
                }

                RateLimiter::hit($dailyRateLimitKey, 86400);
                RateLimiter::hit($rateLimitKey, 60);
            }

            $creditBucket = $creditService->consume($user);
            if ($creditBucket === null) {
                return redirect()->route('zen')->with('credit_exhausted', '本日利用可能なクレジットを使い切りました。クレジット購入または有料プランをご検討ください。');
            }
        } else {
            if (! is_string($guestDeviceToken)) {
                throw new \LogicException('Guest device token is required for guest consultations.');
            }

            if (! $guestTrialCreditService->consume($guestDeviceToken)) {
                return redirect()->route('zen')->with('credit_exhausted', '無料体験を使い切りました。会員登録すると毎日3回相談できます。');
            }
        }

        try {
            $isPremiumMember = $user instanceof User && $user->plan === User::PLAN_PREMIUM;
            $topic = null;
            $history = collect();

            if ($isPremiumMember) {
                $topic = $this->consultationTopic($concern);
                $history = $user->zenConsultations()
                    ->where('topic', $topic)
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
                            ? $this->premiumConsultationPrompt($concern, $history)
                            : "相談：{$concern}",
                    ],
                ],
            ]);

            $answer = $response->choices[0]->message->content;
            if (! is_string($answer) || trim($answer) === '') {
                throw new UnexpectedValueException('OpenAI returned no consultation response.');
            }

            $metadata = $isPremiumMember
                ? [
                    'topic' => $topic,
                    'summary' => $this->consultationSummary($concern, $answer),
                ]
                : $this->consultationMetadata($concern, $answer);
            $consultation = [
                'concern' => $concern,
                'response' => $answer,
                ...$metadata,
            ];
            $shareToken = $shareService->create($consultation);

            if ($user instanceof User) {
                $creditSummary = $creditService->balance($user);
                $creditsRemaining = $creditSummary['total'];
                $user->zenConsultations()->create($consultation);
            } else {
                $creditsRemaining = $guestTrialCreditService->remaining($guestDeviceToken);
                $sessionConsultations = $request->session()->get('zen_consultations', []);
                $sessionConsultations[] = $consultation;
                $request->session()->put('zen_consultations', $sessionConsultations);
            }
        } catch (Throwable $exception) {
            if ($user instanceof User && $creditBucket !== null) {
                $creditService->refund($user, $creditBucket);
            } elseif ($guestDeviceToken !== null) {
                $guestTrialCreditService->refund($guestDeviceToken);
            }

            throw $exception;
        }

        return view('zen-result', [
            ...$consultation,
            'shareUrl' => route('consultations.share', ['token' => $shareToken]),
            'creditsRemaining' => $creditsRemaining,
            'isGuest' => ! $user instanceof User,
        ]);
    }

    /**
     * @return array{concern: string, response: string, topic: string, summary: string}
     */
    private function createDailyConsultation(string $concern): array
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
                    'content' => "相談：{$concern}",
                ],
            ],
            'response_format' => ['type' => 'json_object'],
        ]);

        $content = $response->choices[0]->message->content;
        if (! is_string($content)) {
            throw new UnexpectedValueException('OpenAI returned no daily Zen message.');
        }

        $result = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $answer = is_array($result) ? ($result['response'] ?? null) : null;
        $topic = is_array($result) ? ($result['topic'] ?? null) : null;
        $summary = is_array($result) ? ($result['summary'] ?? null) : null;
        if (
            ! is_string($answer)
            || trim($answer) === ''
            || ! is_string($topic)
            || ! in_array($topic, ZenConsultation::TOPICS, true)
            || ! is_string($summary)
            || trim($summary) === ''
            || mb_strlen($summary) > ZenConsultation::SUMMARY_MAX_LENGTH
        ) {
            throw new UnexpectedValueException('OpenAI returned invalid daily Zen message data.');
        }

        return [
            'concern' => '今日のメッセージ',
            'response' => $answer,
            'topic' => $topic,
            'summary' => $summary,
        ];
    }

    private function consultationTopic(string $concern): string
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
                    'content' => $concern,
                ],
            ],
        ]);

        $topic = $response->choices[0]->message->content;
        if (! is_string($topic) || ! in_array(trim($topic), ZenConsultation::TOPICS, true)) {
            throw new UnexpectedValueException('OpenAI returned an invalid consultation topic.');
        }

        return trim($topic);
    }

    /**
     * @param  Collection<int, ZenConsultation>  $history
     */
    private function premiumConsultationPrompt(string $concern, Collection $history): string
    {
        $historyContext = $history->isEmpty()
            ? '該当テーマの過去の相談記録はありません。'
            : $history
                ->map(fn (ZenConsultation $consultation): string => "- {$consultation->created_at->format('Y/m/d H:i')}：{$consultation->summary}")
                ->implode("\n");

        return "過去の同テーマの相談要約と日時：\n{$historyContext}\n\n今回の相談：{$concern}";
    }

    private function zenTeacherInstructions(): string
    { return '[REDACTED]'; }

    private function consultationSummary(string $concern, string $answer): string
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
                    'content' => "相談：{$concern}\n回答：{$answer}",
                ],
            ],
            'response_format' => ['type' => 'json_object'],
        ]);

        $content = $response->choices[0]->message->content;
        if (! is_string($content)) {
            throw new UnexpectedValueException('OpenAI returned no consultation summary.');
        }

        $result = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $summary = is_array($result) ? ($result['summary'] ?? null) : null;
        if (
            ! is_string($summary)
            || trim($summary) === ''
            || mb_strlen($summary) > ZenConsultation::SUMMARY_MAX_LENGTH
        ) {
            throw new UnexpectedValueException('OpenAI returned an invalid consultation summary.');
        }

        return $summary;
    }

    /**
     * @return array{topic: string, summary: string}
     */
    private function consultationMetadata(string $concern, string $answer): array
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
                    'content' => "相談：{$concern}\n回答：{$answer}",
                ],
            ],
            'response_format' => ['type' => 'json_object'],
        ]);

        $content = $response->choices[0]->message->content;
        if (! is_string($content)) {
            throw new UnexpectedValueException('OpenAI returned no consultation metadata.');
        }

        $metadata = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $topic = is_array($metadata) ? ($metadata['topic'] ?? null) : null;
        $summary = is_array($metadata) ? ($metadata['summary'] ?? null) : null;

        if (
            ! is_string($topic)
            || ! in_array($topic, ZenConsultation::TOPICS, true)
            || ! is_string($summary)
            || trim($summary) === ''
            || mb_strlen($summary) > ZenConsultation::SUMMARY_MAX_LENGTH
        ) {
            throw new UnexpectedValueException('OpenAI returned invalid consultation metadata.');
        }

        return [
            'topic' => $topic,
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
