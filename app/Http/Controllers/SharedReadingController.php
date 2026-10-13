<?php

namespace App\Http\Controllers;

use App\Services\PublicReadingShareService;
use App\Support\TarotCardCatalog;
use Illuminate\Contracts\View\View;

class SharedReadingController extends Controller
{
    public function __invoke(string $token, PublicReadingShareService $shareService): View
    {
        $reading = $shareService->find($token);
        abort_if($reading === null, 404);

        $card = is_string($reading['card']) ? TarotCardCatalog::find($reading['card']) : null;

        return view('readings.shared', [
            'reading' => $reading,
            'cardNumber' => $card['number'] ?? '',
            'cardSymbol' => $card['symbol'] ?? '✧',
            'cardEnglishName' => $card['english_name'] ?? $reading['card'],
        ]);
    }
}
