<!-- app/Http/Controllers/TarotController.php -->

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TarotController extends Controller
{
    public function index()
    {
        return view('tarot');
    }

    public function draw(Request $request)
    {
        $validated = $request->validate([
            'question' => [
                'required',
                'string',
                'max:200'
            ],
        ]);
        $question = $validated['question'];

        $cards = [
            '愚者',
            '魔術師',
            '女教皇',
            '女帝',
            '皇帝',
            '教皇',
            '恋人たち',
            '戦車',
            '力',
            '隠者',
            '運命の輪',
            '正義',
            '吊るされた男',
            '死神',
            '節制',
            '悪魔',
            '塔',
            '星',
            '月',
            '太陽',
            '審判',
            '世界',
        ];

        $card = $cards[array_rand($cards)];

        return view('tarot-result', [
            'question' => $question,
            'card' => $card,
        ]);
    }
}
