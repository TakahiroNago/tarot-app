@php
    $editing = $product !== null;
    $selectedCategoryIds = old('category_ids', $editing ? $product->categories->pluck('id')->all() : []);
    $action = $editing ? route('admin.products.update', $product) : route('admin.products.store');
@endphp
<form class="question-form admin-product-form" method="POST" action="{{ $action }}" enctype="multipart/form-data">
    @csrf
    @if ($editing) @method('PUT') @endif
    <label class="question-label" for="product-name">商品名</label>
    <input class="question-input" id="product-name" name="name" type="text" value="{{ old('name', $product?->name) }}" maxlength="255" required>
    @error('name')<p class="form-error" role="alert">{{ $message }}</p>@enderror

    <label class="question-label" for="product-slug">URL識別子</label>
    <input class="question-input" id="product-slug" name="slug" type="text" value="{{ old('slug', $product?->slug) }}" maxlength="160" required>
    @error('slug')<p class="form-error" role="alert">{{ $message }}</p>@enderror

    <label class="question-label" for="product-description">商品説明</label>
    <textarea class="question-input" id="product-description" name="description" rows="5" maxlength="20000">{{ old('description', $product?->description) }}</textarea>
    @error('description')<p class="form-error" role="alert">{{ $message }}</p>@enderror

    <label class="question-label" for="product-status">販売状態</label>
    <select class="question-input" id="product-status" name="status" required>
        <option value="draft" @selected(old('status', $product?->status ?? 'draft') === 'draft')>下書き</option>
        <option value="published" @selected(old('status', $product?->status) === 'published')>販売中</option>
        <option value="archived" @selected(old('status', $product?->status) === 'archived')>販売停止</option>
    </select>

    <label class="question-label" for="product-sku">SKU（初期は商品につき1件）</label>
    <input class="question-input" id="product-sku" name="sku" type="text" value="{{ old('sku', $variant?->sku) }}" maxlength="100" required>
    @error('sku')<p class="form-error" role="alert">{{ $message }}</p>@enderror

    <label class="question-label" for="variant-label">バリエーション名</label>
    <input class="question-input" id="variant-label" name="variant_label" type="text" value="{{ old('variant_label', $variant?->label ?? '標準') }}" maxlength="255">

    <label class="question-label" for="price-minor">税込価格（JPY）</label>
    <input class="question-input" id="price-minor" name="price_minor" type="number" value="{{ old('price_minor', $variant?->price_minor) }}" min="0" max="1000000000" required>
    @error('price_minor')<p class="form-error" role="alert">{{ $message }}</p>@enderror

    <label class="question-label" for="product-stock">実在庫数</label>
    <input class="question-input" id="product-stock" name="stock" type="number" value="{{ old('stock', $stock) }}" min="0" max="1000000" required>
    @error('stock')<p class="form-error" role="alert">{{ $message }}</p>@enderror
    <label class="question-label" for="stock-reason">在庫変更理由</label>
    <input class="question-input" id="stock-reason" name="stock_reason" type="text" value="{{ old('stock_reason', $editing ? '' : '初期登録') }}" maxlength="500" required>

    <fieldset class="shop-category-options">
        <legend class="question-label">カテゴリ</legend>
        @foreach ($categories as $category)
            <label>
                <input type="checkbox" name="category_ids[]" value="{{ $category->id }}" @checked(in_array($category->id, $selectedCategoryIds))>
                {{ $category->name }}
            </label>
        @endforeach
    </fieldset>

    <label class="shop-checkbox">
        <input type="checkbox" name="reading_recommendable" value="1" @checked(old('reading_recommendable', $product?->reading_recommendable ?? false))>
        占い結果のランダム紹介対象
    </label>

    <label class="question-label" for="product-images">商品画像（JPEG/PNG/WebP、5MB以下、最大8枚/回）</label>
    <input id="product-images" name="images[]" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple>
    @error('images')<p class="form-error" role="alert">{{ $message }}</p>@enderror
    @error('images.*')<p class="form-error" role="alert">{{ $message }}</p>@enderror
    <label class="question-label" for="image-alt-texts">画像の代替テキスト（任意、画像順）</label>
    <input class="question-input" id="image-alt-texts" name="image_alt_texts[0]" type="text" maxlength="255" placeholder="1枚目の説明">
    <input class="question-input" name="image_alt_texts[1]" type="text" maxlength="255" aria-label="2枚目の画像説明">
    <input class="question-input" name="image_alt_texts[2]" type="text" maxlength="255" aria-label="3枚目の画像説明">
    <input class="question-input" name="image_alt_texts[3]" type="text" maxlength="255" aria-label="4枚目の画像説明">
    <input class="question-input" name="image_alt_texts[4]" type="text" maxlength="255" aria-label="5枚目の画像説明">
    <input class="question-input" name="image_alt_texts[5]" type="text" maxlength="255" aria-label="6枚目の画像説明">
    <input class="question-input" name="image_alt_texts[6]" type="text" maxlength="255" aria-label="7枚目の画像説明">
    <input class="question-input" name="image_alt_texts[7]" type="text" maxlength="255" aria-label="8枚目の画像説明">

    @if ($errors->any())
        <div class="admin-errors" role="alert">
            @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif
    <div class="admin-actions">
        <button class="primary-action" type="submit">{{ $editing ? '変更を保存' : '商品を登録' }}</button>
        <a class="secondary-action" href="{{ route('admin.products.index') }}">一覧へ戻る</a>
    </div>
</form>
