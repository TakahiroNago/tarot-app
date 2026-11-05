<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $product->name }} | 月詠 TAROT JOURNAL</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content shop-content">
            <a class="text-link" href="{{ route('shop.products.index') }}">商品一覧へ戻る</a>
            <article class="product-detail">
                <div class="product-gallery">
                    @forelse ($product->images as $image)
                        <img src="{{ Storage::disk($image->storage_disk)->url($image->path) }}" alt="{{ $image->alt_text ?: $product->name }}" loading="lazy">
                    @empty
                        <div class="product-image-placeholder" aria-hidden="true">✦</div>
                    @endforelse
                </div>
                <div class="product-detail-copy">
                    <p class="eyebrow">PRODUCT</p>
                    <h1>{{ $product->name }}</h1>
                    @foreach ($product->categories as $category)
                        <span class="product-category">{{ $category->name }}</span>
                    @endforeach
                    <div class="product-description">{{ $product->description }}</div>
                    @php($variant = $product->variants->first())
                    @if ($variant)
                        @php($available = $variant->inventoryBalance?->available() ?? 0)
                        <p class="product-price product-price-large">¥{{ number_format($variant->price_minor) }} <span>税込</span></p>
                        <p class="product-sku">SKU：{{ $variant->sku }}</p>
                        @if ($available > 0)
                            <p class="product-stock">在庫あり</p>
                            <form class="cart-add-form" method="POST" action="{{ route('shop.cart.store') }}">
                                @csrf
                                <input type="hidden" name="variant_id" value="{{ $variant->id }}">
                                <label for="quantity">数量</label>
                                <input class="question-input" id="quantity" name="quantity" type="number" min="1" max="{{ min(99, $available) }}" value="1" required>
                                <button class="primary-action" type="submit">カートに追加</button>
                            </form>
                        @else
                            <p class="product-stock is-unavailable">在庫切れ</p>
                        @endif
                    @else
                        <p class="product-stock is-unavailable">販売準備中</p>
                    @endif
                    @if (session('status'))
                        <p class="form-notice" role="status">{{ session('status') }}</p>
                    @endif
                    @error('variant_id')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                    @error('quantity')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                </div>
            </article>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
