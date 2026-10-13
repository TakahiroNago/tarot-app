<?php

use App\Services\ConsultationResponseFormatter;

test('markdown headings, lists and emphasis are rendered as html', function () {
    $html = (new ConsultationResponseFormatter)->toHtml("### 1. 見出し\n\n- 項目\n\n**重要**です。");

    expect($html)->toContain('<h3>1. 見出し</h3>')
        ->and($html)->toContain('<li>項目</li>')
        ->and($html)->toContain('<strong>重要</strong>');
});

test('bold text closed after japanese punctuation is rendered', function () {
    $html = (new ConsultationResponseFormatter)->toHtml('次に、**「相手にどう思われるか」ではなく、「私は今、無理をしていないか」**を確かめてください。');

    expect($html)->toContain('<strong>「相手にどう思われるか」ではなく、「私は今、無理をしていないか」</strong>を')
        ->and($html)->not->toContain('**');
});

test('raw html in responses is not rendered', function () {
    $html = (new ConsultationResponseFormatter)->toHtml("<script>alert('unsafe')</script>");

    expect($html)->not->toContain('<script>');
});
