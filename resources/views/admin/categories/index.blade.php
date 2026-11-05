<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>カテゴリ管理 | 月詠 TAROT JOURNAL</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content admin-content">
            <section class="admin-panel">
                <p class="eyebrow">CATEGORIES</p>
                <h1>カテゴリ管理</h1>
                @if (session('status'))<p class="form-notice" role="status">{{ session('status') }}</p>@endif
                <form class="question-form category-create-form" method="POST" action="{{ route('admin.categories.store') }}">
                    @csrf
                    <label class="question-label" for="category-name">カテゴリ名</label>
                    <input class="question-input" id="category-name" name="name" type="text" maxlength="100" required>
                    <label class="question-label" for="category-slug">URL識別子</label>
                    <input class="question-input" id="category-slug" name="slug" type="text" maxlength="120" required>
                    <label class="question-label" for="category-sort-order">表示順</label>
                    <input class="question-input" id="category-sort-order" name="sort_order" type="number" min="0" max="65535" value="0" required>
                    <button class="primary-action" type="submit">カテゴリを追加</button>
                </form>
                @error('name')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                @error('slug')<p class="form-error" role="alert">{{ $message }}</p>@enderror
                <div class="category-admin-list">
                    @foreach ($categories as $category)
                        <div class="category-admin-row">
                            <form method="POST" action="{{ route('admin.categories.update', $category) }}">
                                @csrf
                                @method('PUT')
                                <label class="sr-only" for="category-name-{{ $category->id }}">カテゴリ名</label>
                                <input class="question-input" id="category-name-{{ $category->id }}" name="name" type="text" value="{{ $category->name }}" maxlength="100" required>
                                <label class="sr-only" for="category-slug-{{ $category->id }}">URL識別子</label>
                                <input class="question-input" id="category-slug-{{ $category->id }}" name="slug" type="text" value="{{ $category->slug }}" maxlength="120" required>
                                <label class="sr-only" for="category-sort-{{ $category->id }}">表示順</label>
                                <input class="question-input" id="category-sort-{{ $category->id }}" name="sort_order" type="number" min="0" max="65535" value="{{ $category->sort_order }}" required>
                                <input type="hidden" name="is_active" value="0">
                                <label><input type="checkbox" name="is_active" value="1" @checked($category->is_active)> 公開</label>
                                <span>商品 {{ $category->products_count }}件</span>
                                <button class="secondary-action" type="submit">更新</button>
                            </form>
                            @if ($category->is_active)
                                <form method="POST" action="{{ route('admin.categories.destroy', $category) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="danger-action" type="submit">非公開</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
                {{ $categories->links() }}
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
