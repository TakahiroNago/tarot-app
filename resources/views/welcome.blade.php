{{-- welcome.blade.php --}}

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <title>タロット占い</title>
</head>
<body>
    <h1>タロット占い</h1>

    <p>あなたのためのタロット占い</p>

    <a href="{{ route('tarot') }}">
        占いを始める
    </a>
</body>
</html>
