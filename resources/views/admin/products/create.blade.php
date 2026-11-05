<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>商品登録 | 月詠 TAROT JOURNAL</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content admin-content">
            <section class="admin-panel admin-form-panel">
                <p class="eyebrow">NEW PRODUCT</p>
                <h1>商品を登録</h1>
                @include('admin.products._form', ['product' => null, 'variant' => null, 'stock' => 0])
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
