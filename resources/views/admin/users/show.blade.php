<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $user->name }} | ユーザー管理 | 月詠 禅のことば</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content admin-content">
            <section class="admin-panel admin-detail">
                <p class="eyebrow">USER DETAILS</p>
                <h1>{{ $user->name }}</h1>
                @if (session('status'))<p class="form-notice" role="status">{{ session('status') }}</p>@endif
                @if ($errors->any())<div class="admin-errors" role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
                <dl class="user-details">
                    <div><dt>メールアドレス</dt><dd>{{ $user->email }}</dd></div>
                    <div><dt>権限</dt><dd>{{ $user->isAdmin() ? 'Admin' : 'User' }}</dd></div>
                    <div><dt>状態</dt><dd>{{ $user->is_active ? '利用中' : '停止中' }}</dd></div>
                    <div><dt>登録日時</dt><dd>{{ $user->created_at->format('Y/m/d H:i') }}</dd></div>
                </dl>
                <div class="admin-actions">
                    <a class="secondary-action" href="{{ route('admin.users.edit', $user) }}">編集</a>
                    <form method="POST" action="{{ route('admin.users.password-reset', $user) }}">
                        @csrf
                        <button class="secondary-action" type="submit">パスワード再設定メールを送信</button>
                    </form>
                    @if (auth()->id() !== $user->id && $user->is_active)
                        <form method="POST" action="{{ route('admin.users.suspend', $user) }}">
                            @csrf
                            @method('PATCH')
                            <button class="danger-action" type="submit">利用停止</button>
                        </form>
                    @endif
                </div>
                <a class="text-link" href="{{ route('admin.users.index') }}">ユーザー一覧へ戻る</a>
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>