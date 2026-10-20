<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ユーザー編集 | 心の庵 ZEN REFLECTIONS</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content admin-content">
            <section class="admin-panel admin-form-panel">
                <p class="eyebrow">EDIT USER</p>
                <h1>ユーザー情報を編集</h1>
                <form class="question-form" method="POST" action="{{ route('admin.users.update', $user) }}">
                    @csrf
                    @method('PUT')
                    <label class="question-label" for="name">お名前</label>
                    <input class="question-input" id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required maxlength="255">
                    @error('name')<p class="form-error">{{ $message }}</p>@enderror

                    <label class="question-label" for="email">メールアドレス</label>
                    <input class="question-input" id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required maxlength="255">
                    @error('email')<p class="form-error">{{ $message }}</p>@enderror

                    <label class="question-label" for="role">権限</label>
                    @if (auth()->id() === $user->id)
                        <input type="hidden" name="role" value="{{ $user->role }}">
                        <p class="readonly-value">{{ $user->isAdmin() ? 'Admin' : 'User' }}（自分自身の権限は変更できません）</p>
                    @else
                        <select class="question-input" id="role" name="role" required>
                            <option value="user" @selected(old('role', $user->role) === 'user')>User</option>
                            <option value="admin" @selected(old('role', $user->role) === 'admin')>Admin</option>
                        </select>
                    @endif
                    @error('role')<p class="form-error">{{ $message }}</p>@enderror

                    <div class="admin-actions">
                        <button class="primary-action" type="submit">変更を保存</button>
                        <a class="secondary-action" href="{{ route('admin.users.show', $user) }}">キャンセル</a>
                    </div>
                </form>
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
