<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use OpenAI\Laravel\Facades\OpenAI;

class TarotController extends Controller
{
    public function index(): View
    {
        return view('tarot');
    }

    public function draw(Request $request): View
    {
        $validated = $request->validate([
            'question' => [
                'required',
                'string',
                'max:200',
            ],
        ]);
        $question = $validated['question'];

        $cards = [
            ['number' => '0', 'symbol' => '✧', 'name' => '愚者', 'english_name' => 'THE FOOL'],
            ['number' => 'I', 'symbol' => '☿', 'name' => '魔術師', 'english_name' => 'THE MAGICIAN'],
            ['number' => 'II', 'symbol' => '☾', 'name' => '女教皇', 'english_name' => 'THE HIGH PRIESTESS'],
            ['number' => 'III', 'symbol' => '♀', 'name' => '女帝', 'english_name' => 'THE EMPRESS'],
            ['number' => 'IV', 'symbol' => '♈︎', 'name' => '皇帝', 'english_name' => 'THE EMPEROR'],
            ['number' => 'V', 'symbol' => '✥', 'name' => '教皇', 'english_name' => 'THE HIEROPHANT'],
            ['number' => 'VI', 'symbol' => '♡', 'name' => '恋人たち', 'english_name' => 'THE LOVERS'],
            ['number' => 'VII', 'symbol' => '⚔', 'name' => '戦車', 'english_name' => 'THE CHARIOT'],
            ['number' => 'VIII', 'symbol' => '♌︎', 'name' => '力', 'english_name' => 'STRENGTH'],
            ['number' => 'IX', 'symbol' => '✶', 'name' => '隠者', 'english_name' => 'THE HERMIT'],
            ['number' => 'X', 'symbol' => '⟳', 'name' => '運命の輪', 'english_name' => 'WHEEL OF FORTUNE'],
            ['number' => 'XI', 'symbol' => '⚖', 'name' => '正義', 'english_name' => 'JUSTICE'],
            ['number' => 'XII', 'symbol' => '⤵', 'name' => '吊るされた男', 'english_name' => 'THE HANGED MAN'],
            ['number' => 'XIII', 'symbol' => '♻︎', 'name' => '死神', 'english_name' => 'DEATH'],
            ['number' => 'XIV', 'symbol' => '⚗', 'name' => '節制', 'english_name' => 'TEMPERANCE'],
            ['number' => 'XV', 'symbol' => '♑︎', 'name' => '悪魔', 'english_name' => 'THE DEVIL'],
            ['number' => 'XVI', 'symbol' => '⚡︎', 'name' => '塔', 'english_name' => 'THE TOWER'],
            ['number' => 'XVII', 'symbol' => '✷', 'name' => '星', 'english_name' => 'THE STAR'],
            ['number' => 'XVIII', 'symbol' => '☽', 'name' => '月', 'english_name' => 'THE MOON'],
            ['number' => 'XIX', 'symbol' => '☼', 'name' => '太陽', 'english_name' => 'THE SUN'],
            ['number' => 'XX', 'symbol' => '🎺︎', 'name' => '審判', 'english_name' => 'JUDGEMENT'],
            ['number' => 'XXI', 'symbol' => '◎', 'name' => '世界', 'english_name' => 'THE WORLD'],
        ];

        $selectedCard = $cards[array_rand($cards)];

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
        $reading = [
            'question' => $question,
            'card' => $selectedCard['name'],
            'interpretation' => $interpretation,
        ];

        if ($request->user() !== null) {
            $request->user()->tarotReadings()->create($reading);
        } else {
            $sessionReadings = $request->session()->get('tarot_readings', []);
            $sessionReadings[] = $reading;
            $request->session()->put('tarot_readings', $sessionReadings);
        }

        return view('tarot-result', [
            ...$reading,
            'cardNumber' => $selectedCard['number'],
            'cardSymbol' => $selectedCard['symbol'],
            'cardEnglishName' => $selectedCard['english_name'],
        ]);
    }
}
