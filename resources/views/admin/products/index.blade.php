<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>商品管理 | 月詠 TAROT JOURNAL</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content admin-content">
            <section class="admin-panel">
                <p class="eyebrow">PRODUCTS</p>
                <h1>商品管理</h1>
                @if (session('status'))<p class="form-notice" role="status">{{ session('status') }}</p>@endif
                <div class="admin-actions">
                    <a class="primary-action" href="{{ route('admin.products.create') }}">商品を登録</a>
                    <a class="secondary-action" href="{{ route('admin.categories.index') }}">カテゴリ管理</a>
                </div>
                <form class="user-search" method="GET" action="{{ route('admin.products.index') }}">
                    <label class="sr-only" for="admin-product-search">商品名またはSKUで検索</label>
                    <input class="question-input" id="admin-product-search" name="search" type="search" value="{{ $search }}" placeholder="商品名・SKU">
                    <select class="question-input" name="status" aria-label="販売状態">
                        <option value="">すべての状態</option>
                        <option value="draft" @selected($selectedStatus === 'draft')>下書き</option>
                        <option value="published" @selected($selectedStatus === 'published')>販売中</option>
                        <option value="archived" @selected($selectedStatus === 'archived')>販売停止</option>
                    </select>
                    <button class="secondary-action" type="submit">検索</button>
                </form>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>商品</th><th>SKU</th><th>価格</th><th>在庫</th><th>販売状態</th><th>占い紹介</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($products as $product)
                                @php($variant = $product->variants->first())
                                <tr>
                                    <td><strong>{{ $product->name }}</strong><span>{{ $product->slug }}</span></td>
                                    <td>{{ $variant?->sku ?? '—' }}</td>
                                    <td>{{ $variant ? '¥'.number_format($variant->price_minor) : '—' }}</td>
                                    <td>{{ $variant?->inventoryBalance?->available() ?? 0 }}</td>
                                    <td>{{ ['draft' => '下書き', 'published' => '販売中', 'archived' => '販売停止'][$product->status] ?? $product->status }}</td>
                                    <td>{{ $product->reading_recommendable ? '対象' : '対象外' }}</td>
                                    <td><a class="table-link" href="{{ route('admin.products.edit', $product) }}">編集</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="empty-state">商品はまだ登録されていません。</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $products->links() }}
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
