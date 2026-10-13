<?php

namespace App\Services;

use Illuminate\Support\Str;

class ConsultationResponseFormatter
{
    public function toHtml(string $response): string
    {
        $html = Str::markdown($response, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        // CommonMark does not close emphasis after Japanese punctuation such as 」 when text follows it.
        return preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $html) ?? $html;
    }
}
