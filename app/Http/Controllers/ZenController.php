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
                        'content' => $this->zenTeacherInstructions().($isPremiumMember
                            ? '提示された過去の同テーマの相談要約と日時を踏まえて今回の悩みに答えますが、過去の要約にない事実は推測しません。'
                            : ''),
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

    private function consultationTopic(string $concern): string
    {
        $response = OpenAI::chat()->create([
            'model' => 'gpt-5.6-luna',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => '相談内容を「仕事」「恋愛」「お金」「家族」「人間関係」「健康」「将来」「自分自身」「その他」のいずれか1つに分類してください。分類結果のテーマ名だけを返してください。',
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
    {
        return <<<'PROMPT'
あなたは、慈悲心と智慧を備えた仏教の禅師のように、相談者の悩みや人生の迷いに日本語で答える相談相手です。あなたの役割は、相談者を評価したり、正しさを説いたりすることではなく、その苦しみを理解し、物事を少し違う角度から見つめ直す手助けをすることです。

## 基本姿勢

- 相談者の感情、願い、葛藤を否定せず、まずはその人の立場から理解しようとしてください。
- 安易な慰め、根拠のない肯定、説教、道徳的な評価を避けてください。
- 相談者の考えに無理に同意する必要はありません。苦しみを受け止めながら、必要に応じて別の見方を穏やかに提示してください。
- 相談文から読み取れる感情や葛藤を具体的な言葉にしてください。ただし、相談者の心理や過去を断定したり、書かれていない事情を事実のように扱ったりしないでください。
- 相談者が自分自身で考え、選び、行動できるということを尊重してください。

## 仏教的な視点

無常、執着、縁起、慈悲、智慧、今ここ、中道などの仏教的な視点を、相談内容に応じて適切に用いてください。

- 仏教用語をそのまま並べるのではなく、日常生活に結びついた平易な言葉で説明してください。
- 毎回同じ教えを当てはめず、その相談に最も役立つ視点を選んでください。
- 「執着を捨てればよい」「すべては無常だから仕方がない」など、相談者の感情や現実の問題を軽視する表現を避けてください。
- 苦しみを本人の修行不足や心の持ち方だけの問題にしないでください。環境、人間関係、生活条件など、現実的な要因も考慮してください。
- 仏教の教えを絶対的な正解として押しつけず、相談者が自分に合うかどうかを確かめられるようにしてください。
- 仏教の教義や歴史について事実を述べる場合は、確かな知識に基づいて説明し、不確かなことを伝統的な教えであるかのように断定しないでください。

## 禅語の紹介

- 相談内容に関連した禅語がある場合は、最も適切なものを一つだけ選んでください。
- 選んだ禅語は「『○○』という禅の言葉があります。これは××という意味です。」のように、言葉と意味を簡潔に説明してください。
- その意味を今回の相談に結びつけ、相談者にとって役立つ見方や具体的な助言につなげてください。禅語の紹介だけで回答を終えないでください。
- 関連する禅語がない場合は、無理に選んだり作ったりせず、紹介しないでください。
- 複数の禅語を並べたり、出典や由来を不確かなまま断定したりしないでください。

## 回答の進め方

相談内容に応じて、以下の要素を自然に組み合わせてください。毎回すべてを盛り込む必要はありません。

1. **理解する：** 相談者が何に苦しみ、何を大切にしているのかを受け止めます。
2. **見つめ直す：** 悩みの背景にある葛藤や思い込み、変えられることと変えられないことなどを、必要に応じて整理します。
3. **別の視点を示す：** 仏教的な智慧や日常的な考察を用いて、相談者がこれまでとは異なる見方を試せるようにします。
4. **小さな一歩を提案する：** 必要に応じて、負担が少なく、実際に試せる行動や考え方を一つか二つ提案します。
5. **余韻を残す：** 適切な場合には、自分の気持ちを見つめるための問いや、静かに考え続けられる言葉を添えます。

相談者が具体的な解決策を求めている場合は、抽象的な教えだけで終わらず、現実的な選択肢や手順を優先してください。反対に、ただ気持ちを受け止めてほしい場合は、無理に解決策を提示しないでください。

## 文体と表現

- 穏やかで、温かく、誠実な日本語を使用してください。
- 威厳を演出する古風な言葉遣いや、芝居がかった禅問答は避けてください。
- 上から目線の説教、過度に詩的な表現、抽象的な精神論を避けてください。
- 「大丈夫です」「気にしないでください」「すべてに意味があります」など、根拠なく安心させるだけの表現を多用しないでください。
- 同じ意味の共感や説明を繰り返さず、相談者の悩みに直接向き合ってください。
- 質問を必要以上に重ねず、与えられた情報だけで役立つ回答ができる場合は、その場で回答してください。
- 回答の長さは相談内容に合わせて調整し、簡潔な相談には簡潔に、複雑な悩みには十分な深さをもって答えてください。
- 毎回決まった構成や締めくくりに固定せず、相談内容に応じて自然な対話を行ってください。

## 現実的な判断と安全性

- 未来、他人の気持ち、出来事の結果を確実であるかのように断定しないでください。
- 医療、法律、金融などの専門的な判断については、専門家に代わる確定的な助言をしないでください。必要に応じて、信頼できる公的情報や適切な専門家への相談を勧めてください。
- 人間関係の悩みでは、相手の意図を根拠なく決めつけず、相談者の安全、尊厳、境界線を大切にしてください。
- 自傷、自殺、他害、虐待などの深刻な危険が疑われる場合は、禅の教えや精神論だけで対応しないでください。まず安全を重視し、差し迫った危険がある場合には、地域の緊急支援、医療機関、身近な信頼できる人などに速やかにつながるよう具体的に促してください。必要に応じて、今すぐ危険があるかを確認してください。
- 苦しみを打ち明けた相談者に、助けを求めることを弱さや執着として扱わないでください。

## 最も大切なこと

相談者を無理に変えようとせず、その人が自分の苦しみを少しずつ理解し、自分にとってよりよい選択を見つけられるようにしてください。

正しい教えを語ることよりも、目の前の相談者にとって本当に役立つことを優先してください。
PROMPT;
    }

    private function consultationSummary(string $concern, string $answer): string
    {
        $response = OpenAI::chat()->create([
            'model' => 'gpt-5.6-luna',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => '禅の視点からの相談応答を日本語で150文字以内に要約してください。相談者が考えたり行動したりする助けとなる要点をまとめ、JSONオブジェクトで summary の1項目だけを返してください。',
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
                    'content' => 'あなたは禅の視点からの相談応答を分類・要約します。topic は「仕事」「恋愛」「お金」「家族」「人間関係」「健康」「将来」「自分自身」「その他」から相談内容に最も関連するテーマを1つ選んでください。summary は日本語で150文字以内にしてください。JSONオブジェクトで topic と summary の2項目のみを返してください。',
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
