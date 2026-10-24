<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\ZenConsultation;
use App\Services\ConsultationCreditService;
use App\Services\GuestTrialCreditService;
use App\Services\PersonalInfoDetector;
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
        PersonalInfoDetector $personalInfoDetector,
    ): View|RedirectResponse {
        $validated = $request->validate([
            'concern' => [
                'required',
                'string',
                'max:200',
            ],
            'confirm_personal_info' => ['nullable', 'boolean'],
        ]);
        $concern = $validated['concern'];
        $containsPersonalInfo = $personalInfoDetector->detect($concern) !== [];
        if ($containsPersonalInfo && ! $request->boolean('confirm_personal_info')) {
            $anonymizedConcern = $personalInfoDetector->redact($concern);

            return redirect()->route('zen')
                ->withInput()
                ->with('personal_info_detected', true)
                ->with('anonymized_concern', $personalInfoDetector->detect($anonymizedConcern) === [] && mb_strlen($anonymizedConcern) <= 200 ? $anonymizedConcern : null)
                ->withErrors([
                    'concern' => __('メールアドレス・電話番号・住所などの個人情報と思われる内容が含まれています。削除してから送信してください。このまま送信する場合は、下のチェックを入れてください。'),
                ]);
        }
        $user = $request->user();
        $guestDeviceToken = $user instanceof User ? null : $this->guestDeviceToken($request);
        $lockIdentity = $user instanceof User
            ? 'user:'.$user->getAuthIdentifier()
            : 'guest:'.hash('sha256', $guestDeviceToken);
        $lock = Cache::lock('zen-consultation:'.$lockIdentity, self::RESPONSE_LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            return redirect()->route('zen')->with(
                'consultation_rate_limited',
                __('別の相談に回答中です。完了後にもう一度お試しください。'),
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
                $containsPersonalInfo,
            );
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
        bool $containsPersonalInfo,
    ): View|RedirectResponse {
        $creditBucket = null;

        if ($user instanceof User) {
            if ($user->plan === User::PLAN_PREMIUM) {
                $dailyRateLimitKey = 'premium-daily-consultation:'.$user->getAuthIdentifier().':'.now()->toDateString();
                if (RateLimiter::tooManyAttempts($dailyRateLimitKey, self::PREMIUM_DAILY_LIMIT)) {
                    return redirect()->route('zen')->with(
                        'consultation_rate_limited',
                        __('本日のプレミアムプランの利用上限に達しました。明日以降に再度お試しください。'),
                    );
                }

                $rateLimitKey = 'premium-consultation:'.$user->getAuthIdentifier();
                if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
                    $secondsUntilAvailable = RateLimiter::availableIn($rateLimitKey);

                    return redirect()->route('zen')->with(
                        'consultation_rate_limited',
                        __('プレミアムプランの相談は1分あたり5回までです。:seconds秒後に再度お試しください。', [
                            'seconds' => $secondsUntilAvailable,
                        ]),
                    );
                }

                RateLimiter::hit($dailyRateLimitKey, 86400);
                RateLimiter::hit($rateLimitKey, 60);
            }

            $creditBucket = $creditService->consume($user);
            if ($creditBucket === null) {
                return redirect()->route('zen')->with('credit_exhausted', __('本日利用可能なクレジットを使い切りました。クレジット購入または有料プランをご検討ください。'));
            }
        } else {
            if (! is_string($guestDeviceToken)) {
                throw new \LogicException('Guest device token is required for guest consultations.');
            }

            if (! $guestTrialCreditService->consume($guestDeviceToken)) {
                return redirect()->route('zen')->with('credit_exhausted', __('無料体験を使い切りました。会員登録すると毎日1回相談できます。'));
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
                        'content' => $this->zenPrompt('teacher.'.(app()->isLocale('en') ? 'en' : 'ja'))
                            .($isPremiumMember
                                ? $this->zenPrompt('premium_history_system.'.(app()->isLocale('ja') ? 'ja' : 'en'))
                                : ''),
                    ],
                    [
                        'role' => 'user',
                        'content' => $isPremiumMember
                            ? $this->premiumConsultationPrompt($concern, $history)
                            : $this->formatZenPrompt(
                                'concern_user.'.(app()->isLocale('ja') ? 'ja' : 'en'),
                                ['{concern}' => $concern],
                            ),
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
            $shareToken = $shareService->create($consultation, $containsPersonalInfo);

            if ($user instanceof User) {
                $creditSummary = $creditService->balance($user);
                $creditsRemaining = $creditSummary['total'];
                $user->zenConsultations()->create([
                    ...$consultation,
                    'share_token_hash' => $shareService->tokenHash($shareToken),
                    'contains_personal_info' => $containsPersonalInfo,
                ]);
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

    private function consultationTopic(string $concern): string
    {
        $response = OpenAI::chat()->create([
            'model' => 'gpt-5.6-luna',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->zenPrompt('topic.ja'),
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
        $locale = app()->isLocale('ja') ? 'ja' : 'en';

        $historyText = $history->isEmpty()
            ? $this->zenPrompt('history_empty.'.$locale)
            : $history
                ->map(fn (ZenConsultation $consultation): string => $this->formatZenPrompt(
                    'history_list_item.'.$locale,
                    [
                        '{date}' => $consultation->created_at->format($locale === 'ja' ? 'Y/m/d H:i' : 'M j, Y H:i'),
                        '{summary}' => (string) $consultation->summary,
                    ]
                ))
                ->implode("\n");

        return $this->formatZenPrompt('history_user.'.$locale, [
            '{history}' => $historyText,
            '{concern}' => $concern,
        ]);
    }

    private function zenPrompt(string $name): string
    {
        $prompt = config("zen.prompts.{$name}");

        if (! is_string($prompt) || trim($prompt) === '') {
            throw new UnexpectedValueException("Zen prompt [{$name}] is missing or invalid.");
        }

        return $prompt;
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function formatZenPrompt(string $name, array $replacements): string
    {
        return strtr($this->zenPrompt($name), $replacements);
    }

    private function consultationSummary(string $concern, string $answer): string
    {
        $response = OpenAI::chat()->create([
            'model' => 'gpt-5.6-luna',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->zenPrompt('summary.'.(app()->isLocale('ja') ? 'ja' : 'en')),
                ],
                [
                    'role' => 'user',
                    'content' => $this->formatZenPrompt(
                        'summary_user.'.(app()->isLocale('ja') ? 'ja' : 'en'),
                        ['{concern}' => $concern, '{answer}' => $answer],
                    ),
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
                    'content' => $this->zenPrompt('metadata.'.(app()->isLocale('ja') ? 'ja' : 'en')),
                ],
                [
                    'role' => 'user',
                    'content' => $this->formatZenPrompt(
                        'metadata_user.'.(app()->isLocale('ja') ? 'ja' : 'en'),
                        ['{concern}' => $concern, '{answer}' => $answer],
                    ),
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
