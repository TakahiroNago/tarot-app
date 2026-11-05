<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>カート | 月詠 TAROT JOURNAL</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content shop-content">
            <section class="shop-panel">
                <p class="eyebrow">SHOPPING CART</p>
                <h1>カート</h1>
                @if (session('status'))
                    <p class="form-notice" role="status">{{ session('status') }}</p>
                @endif
                @if ($hasPendingOrder)
                    <p class="form-notice" role="status">お支払い待ちのご注文があり、その分の在庫を予約しています。数量を増やせない場合は、<a class="text-link" href="{{ route('shop.checkout.index') }}">お支払い待ちのご注文</a>を取り消すとお試しいただけます。</p>
                @endif
                @if ($cart->items->isNotEmpty())
                    <div class="cart-list">
                        @foreach ($cart->items as $item)
                            @php($product = $item->variant->product)
                            @php($available = $item->variant->inventoryBalance?->available() ?? 0)
                            <article class="cart-item">
                                <div>
                                    <h2>{{ $product?->name ?? '販売終了商品' }}</h2>
                                    <p>{{ $item->variant->label }} / SKU: {{ $item->variant->sku }}</p>
                                    <p class="product-price">¥{{ number_format($item->variant->price_minor) }} <span>税込</span></p>
                                    @if ($product?->status !== 'published' || $available < $item->quantity)
                                        <p class="form-error">現在の在庫ではこの数量を購入できません。</p>
                                    @endif
                                </div>
                                <div class="cart-item-actions">
                                    <form method="POST" action="{{ route('shop.cart.update', $item) }}">
                                        @csrf
                                        @method('PATCH')
                                        <label for="quantity-{{ $item->id }}">数量</label>
                                        <input class="question-input" id="quantity-{{ $item->id }}" name="quantity" type="number" min="1" max="99" value="{{ $item->quantity }}" required>
                                        <button class="secondary-action" type="submit">更新</button>
                                    </form>
                                    <form method="POST" action="{{ route('shop.cart.destroy', $item) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="danger-action" type="submit">削除</button>
                                    </form>
                                </div>
                            </article>
                        @endforeach
                    </div>
                    <div class="cart-total">
                        <span>小計（税込）</span>
                        <strong>¥{{ number_format($subtotal) }}</strong>
                    </div>
                    <p class="page-description">送料・税額は配送先を入力して確認します。</p>
                    <a class="primary-action" href="{{ route('shop.checkout.index') }}">ご注文手続きへ <span class="action-arrow" aria-hidden="true">→</span></a>
                @else
                    <p class="history-empty">カートに商品はありません。</p>
                    <a class="primary-action" href="{{ route('shop.products.index') }}">商品を見る <span class="action-arrow" aria-hidden="true">→</span></a>
                @endif
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
