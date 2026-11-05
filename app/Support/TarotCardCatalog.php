<?php

namespace App\Support;

class TarotCardCatalog
{
    /**
     * @return list<array{number: string, symbol: string, name: string, english_name: string}>
     */
    public static function all(): array
    {
        return [
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
    }

    /**
     * @return array{number: string, symbol: string, name: string, english_name: string}|null
     */
    public static function find(string $name): ?array
    {
        foreach (self::all() as $card) {
            if ($card['name'] === $name || strcasecmp($card['english_name'], $name) === 0) {
                return $card;
            }
        }

        return null;
    }
}
