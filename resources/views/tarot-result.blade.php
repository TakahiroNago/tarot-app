<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <title>タロット結果</title>
</head>
<body>
    <h1>タロット結果</h1>

    <h2>あなたの質問</h2>
    <p>{{ $question }}</p>

    <h2>引いたカード</h2>
    <p>{{ $card }}</p>

    <a href="{{ route('tarot') }}">
        もう一度占う
    </a>
</body>
</html>
