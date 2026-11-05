<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>商品一覧 | 月詠 TAROT JOURNAL</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content shop-content">
            <section class="shop-panel">
                <p class="eyebrow">SHOP</p>
                <h1>商品一覧</h1>
                <form class="shop-filters" method="GET" action="{{ route('shop.products.index') }}">
                    <label class="sr-only" for="shop-search">商品を検索</label>
                    <input class="question-input" id="shop-search" name="search" type="search" value="{{ $search }}" maxlength="100" placeholder="商品名・説明・SKU">
                    <label class="sr-only" for="shop-category">カテゴリ</label>
                    <select class="question-input" id="shop-category" name="category">
                        <option value="">すべてのカテゴリ</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->slug }}" @selected($selectedCategory === $category->slug)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                    <button class="secondary-action" type="submit">検索</button>
                </form>

                @if ($products->isNotEmpty())
                    <div class="product-grid">
                        @foreach ($products as $product)
                            @php($variant = $product->variants->first())
                            <article class="product-card">
                                <a class="product-card-image" href="{{ route('shop.products.show', $product->slug) }}">
                                    @if ($product->images->isNotEmpty())
                                        <img src="{{ Storage::disk($product->images->first()->storage_disk)->url($product->images->first()->path) }}" alt="{{ $product->images->first()->alt_text ?: $product->name }}" loading="lazy">
                                    @else
                                        <span class="product-image-placeholder" aria-hidden="true">✦</span>
                                    @endif
                                </a>
                                <div class="product-card-copy">
                                    <h2><a href="{{ route('shop.products.show', $product->slug) }}">{{ $product->name }}</a></h2>
                                    @if ($variant)
                                        <p class="product-price">¥{{ number_format($variant->price_minor) }} <span>税込</span></p>
                                        @if (($variant->inventoryBalance?->available() ?? 0) < 1)
                                            <p class="product-stock is-unavailable">在庫切れ</p>
                                        @endif
                                    @else
                                        <p class="product-stock is-unavailable">販売準備中</p>
                                    @endif
                                    <a class="text-link" href="{{ route('shop.products.show', $product->slug) }}">詳細を見る</a>
                                </div>
                            </article>
                        @endforeach
                    </div>
                    {{ $products->links() }}
                @else
                    <p class="history-empty">該当する商品はありません。</p>
                @endif
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
