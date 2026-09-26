{{-- resoureces/veiws/tarot.blade.php --}}

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <title>タロット占い</title>
</head>
<body>
    <h1>タロット占い</h1>

    <p>質問を入力してください。</p>

    <form method="POST" action="{{ route('tarot.draw') }}">
        @csrf
        <input
            type="text"
            name="question"
            placeholder="質問を入力してください"
        >
        <button type="submit">占う</button>
    </form>
</body>
</html>
