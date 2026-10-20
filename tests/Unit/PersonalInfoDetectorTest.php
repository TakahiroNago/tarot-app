<?php

use App\Services\PersonalInfoDetector;
use Tests\TestCase;

uses(TestCase::class);

dataset('personal info', [
    'email' => ['連絡は taro.yamada@example.com まで', PersonalInfoDetector::EMAIL],
    'mobile phone' => ['090-1234-5678に電話が来ます', PersonalInfoDetector::PHONE],
    'full-width phone' => ['０９０－１２３４－５６７８', PersonalInfoDetector::PHONE],
    'landline' => ['03-1234-5678', PersonalInfoDetector::PHONE],
    'postal code' => ['〒150-0001 に住んでいます', PersonalInfoDetector::POSTAL_CODE],
    'url' => ['https://example.com/me を見て', PersonalInfoDetector::URL],
    'name' => ['同僚の田中さんと上手くいきません', PersonalInfoDetector::NAME],
    'name with president title' => ['山田社長に相談しました', PersonalInfoDetector::NAME],
    'name with department manager title' => ['佐藤部長に確認してください', PersonalInfoDetector::NAME],
    'name with section manager title' => ['鈴木課長が対応します', PersonalInfoDetector::NAME],
    'name with senior managing director title' => ['高橋専務から連絡がありました', PersonalInfoDetector::NAME],
    'company' => ['株式会社サンプル商事で働いています', PersonalInfoDetector::COMPANY],
    'company suffix' => ['山田商事株式会社に勤めています', PersonalInfoDetector::COMPANY],
    'address' => ['東京都渋谷区神南1丁目2番3号に住んでいます', PersonalInfoDetector::ADDRESS],
    'credit card' => ['4111 1111 1111 1111', PersonalInfoDetector::CREDIT_CARD],
]);

test('it detects personal information', function (string $text, string $type) {
    expect((new PersonalInfoDetector)->detect($text))->toContain($type);
})->with('personal info');

test('it does not flag ordinary concerns', function (string $text) {
    expect((new PersonalInfoDetector)->detect($text))->toBe([]);
})->with([
    'age and salary' => ['35歳で年収500万円ですが転職すべきでしょうか'],
    'year' => ['2026年に新しい一歩を踏み出したい'],
    'role words' => ['上司さんやお客様、部長さんとの関係が不安です'],
    'prefecture only' => ['東京都に引っ越すべきか迷っています'],
    'plain' => ['人間関係に疲れてしまいました'],
]);

test('it replaces personal information with placeholders', function () {
    app()->setLocale('ja');
    $redacted = (new PersonalInfoDetector)->redact('田中さんに090-1234-5678で連絡し、株式会社サンプル商事の東京都渋谷区神南1丁目2番3号へ行く。mail: a@example.com');
    $redactedWithTitle = (new PersonalInfoDetector)->redact('山田社長に相談しました');

    expect($redacted)->toBe('[氏名]に[電話番号]で連絡し、[会社名]の[住所]へ行く。mail: [メールアドレス]')
        ->and((new PersonalInfoDetector)->detect($redacted))->toBe([])
        ->and($redactedWithTitle)->toBe('[氏名]に相談しました');
});
