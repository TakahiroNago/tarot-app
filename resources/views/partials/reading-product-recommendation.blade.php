@if ($recommendedProduct instanceof \App\Models\Product)
    @php($canBePurchased = $recommendedProduct->status === 'published' && ! $recommendedProduct->trashed())
    @php($image = $canBePurchased ? $recommendedProduct->images->first() : null)
    @php($imageUrl = $image?->publicUrlOrNull())
    <section class="reading-product-recommendation" aria-labelledby="reading-product-recommendation-heading">
        <h2 class="section-label" id="reading-product-recommendation-heading">今回のリーディングからのおすすめ</h2>
        <div class="reading-product-recommendation-content">
            @if ($imageUrl !== null)
                <img
                    class="reading-product-recommendation-image"
                    src="{{ $imageUrl }}"
                    alt="{{ $image->alt_text ?: $recommendedProduct->name }}"
                    loading="lazy"
                >
            @endif
            <div>
                @if ($canBePurchased)
                    <a class="reading-product-recommendation-link" href="{{ route('shop.products.show', $recommendedProduct->slug) }}">
                        {{ $recommendedProduct->name }}
                    </a>
                    <p class="reading-product-recommendation-note">販売状況・在庫は商品ページでご確認いただけます。</p>
                @else
                    <p class="reading-product-recommendation-name">{{ $recommendedProduct->name }}</p>
                    <p class="reading-product-recommendation-note">この商品は現在ご購入いただけません。</p>
                @endif
            </div>
        </div>
    </section>
@endif
