<?php

namespace App\Services;

class PersonalInfoDetector
{
    public const EMAIL = 'email';

    public const PHONE = 'phone';

    public const POSTAL_CODE = 'postal_code';

    public const URL = 'url';

    public const CREDIT_CARD = 'credit_card';

    public const COMPANY = 'company';

    public const ADDRESS = 'address';

    public const NAME = 'name';

    private const NAME_STOP_WORDS = [
        '上司', '同僚', '先輩', '後輩', '友人', '友達', '彼女', '彼氏', '旦那', '両親',
        '家族', '先生', '医師', '社員', '相手', '本人', '皆', '店員', '担当',
    ];

    private const NAME_STOP_SUFFIXES = ['者', '員', '長', '官', '手', '師'];

    /**
     * @return list<string>
     */
    public function detect(string $text): array
    {
        $normalized = $this->normalize($text);
        $types = [];

        foreach ($this->rules() as $type => $rule) {
            if ($this->matchesOf($rule, $normalized) !== []) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Replaces detected personal information with placeholders, in the order of rules().
     */
    public function redact(string $text): string
    {
        $redacted = $this->normalize($text);

        foreach ($this->rules() as $rule) {
            $redacted = preg_replace_callback(
                $rule['regex'],
                fn (array $match): string => $this->isValid($rule, $match) ? '['.__($rule['label']).']' : $match[0],
                $redacted,
            ) ?? $redacted;
        }

        return $redacted;
    }

    /**
     * @return array<string, array{regex: string, label: string, validator?: string}>
     */
    private function rules(): array
    {
        $han = '\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}々';
        $word = $han.'\x{30A1}-\x{30FA}A-Za-z0-9ー';
        $place = $han.'ぁ-んァ-ヶー';

        return [
            self::EMAIL => [
                'regex' => '/[a-z0-9._%+\-]+@[a-z0-9\-]+(?:\.[a-z0-9\-]+)+/iu',
                'label' => 'メールアドレス',
            ],
            self::URL => [
                'regex' => '/https?:\/\/\S+|www\.\S+|(?<![a-z0-9])@[a-z0-9_]{3,}/iu',
                'label' => 'URL',
            ],
            self::COMPANY => [
                'regex' => "/(?:株式会社|有限会社|合同会社)\\s*[{$word}]{1,20}|[{$word}]{1,20}\\s*(?:株式会社|有限会社|合同会社|\\(株\\)|㈱)/u",
                'label' => '会社名',
            ],
            self::ADDRESS => [
                'regex' => "/(?:(?:北海道|東京都|(?:京都|大阪)府|[{$han}]{2,3}県)[{$place}]{1,12}[市区町村郡]|[{$han}]{1,5}[市区町村])[{$place}]{0,12}\\d+(?:丁目|番地?|-\\d+)[\\d\\-丁目番地号]*/u",
                'label' => '住所',
            ],
            self::POSTAL_CODE => [
                'regex' => '/〒\s*\d{3}-?\d{4}|(?<![\d-])\d{3}-\d{4}(?![\d-])/u',
                'label' => '郵便番号',
            ],
            self::CREDIT_CARD => [
                'regex' => '/(?<!\d)(?:\d[\s-]?){12,18}\d(?!\d)/u',
                'label' => 'カード番号',
                'validator' => 'isCreditCard',
            ],
            self::PHONE => [
                'regex' => '/(?<!\d)(?:\+81[\s-]?|0)\d{1,4}[\s-]?\d{1,4}[\s-]?\d{3,4}(?!\d)/u',
                'label' => '電話番号',
                'validator' => 'isPhoneNumber',
            ],
            self::NAME => [
                'regex' => "/(?<![{$han}])([{$han}]{2,4})(?:さん|様|氏|君|くん|ちゃん)/u",
                'label' => '氏名',
                'validator' => 'isPersonName',
            ],
        ];
    }

    /**
     * @param  array{regex: string, label: string, validator?: string}  $rule
     * @return list<array<int, string>>
     */
    private function matchesOf(array $rule, string $text): array
    {
        preg_match_all($rule['regex'], $text, $matches, PREG_SET_ORDER);

        return array_values(array_filter($matches, fn (array $match): bool => $this->isValid($rule, $match)));
    }

    /**
     * @param  array{regex: string, label: string, validator?: string}  $rule
     * @param  array<int, string>  $match
     */
    private function isValid(array $rule, array $match): bool
    {
        return ! isset($rule['validator']) || $this->{$rule['validator']}($match);
    }

    private function normalize(string $text): string
    {
        $normalized = mb_convert_kana($text, 'as', 'UTF-8');

        return preg_replace('/(?<=\d)[ー−―‐－‑–—](?=\d)/u', '-', $normalized) ?? $normalized;
    }

    /**
     * @param  array<int, string>  $match
     */
    private function isPhoneNumber(array $match): bool
    {
        $digits = preg_replace('/\D/', '', $match[0]);
        $digits = str_starts_with($match[0], '+81') ? '0'.substr($digits, 2) : $digits;

        return preg_match('/\A0(?:[789]0\d{8}|\d{9})\z/', $digits) === 1;
    }

    /**
     * @param  array<int, string>  $match
     */
    private function isCreditCard(array $match): bool
    {
        $digits = preg_replace('/\D/', '', $match[0]);

        return strlen($digits) >= 13 && strlen($digits) <= 19 && $this->passesLuhn($digits);
    }

    /**
     * @param  array<int, string>  $match
     */
    private function isPersonName(array $match): bool
    {
        $name = $match[1];

        return ! in_array($name, self::NAME_STOP_WORDS, true)
            && ! in_array(mb_substr($name, -1), self::NAME_STOP_SUFFIXES, true);
    }

    private function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $double = false;

        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            $digit = (int) $digits[$index];

            if ($double) {
                $digit *= 2;
                $digit -= $digit > 9 ? 9 : 0;
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
