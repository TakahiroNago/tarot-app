<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $product->name }} | 商品管理 | 月詠 TAROT JOURNAL</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content admin-content">
            <section class="admin-panel admin-form-panel">
                <p class="eyebrow">EDIT PRODUCT</p>
                <h1>{{ $product->name }}</h1>
                @if (session('status'))<p class="form-notice" role="status">{{ session('status') }}</p>@endif
                @include('admin.products._form', ['stock' => $variant->inventoryBalance?->on_hand ?? 0])
                @if ($product->images->isNotEmpty())
                    <section class="admin-image-list">
                        <h2>登録済み画像</h2>
                        @foreach ($product->images as $image)
                            <div class="admin-image-row">
                                <img src="{{ Storage::disk($image->storage_disk)->url($image->path) }}" alt="{{ $image->alt_text ?: '' }}">
                                <form method="POST" action="{{ route('admin.products.images.destroy', [$product, $image]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="danger-action" type="submit">画像を削除</button>
                                </form>
                            </div>
                        @endforeach
                    </section>
                @endif
                <form method="POST" action="{{ route('admin.products.destroy', $product) }}" class="admin-archive-form">
                    @csrf
                    @method('DELETE')
                    <button class="danger-action" type="submit">商品を販売停止にする</button>
                </form>
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
