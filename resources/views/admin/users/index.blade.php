<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ユーザー管理 | 心の庵 ZEN REFLECTIONS</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content admin-content">
            <section class="admin-panel">
                <p class="eyebrow">ADMINISTRATION</p>
                <h1>ユーザー管理</h1>
                @if (session('status'))<p class="form-notice" role="status">{{ session('status') }}</p>@endif
                <form class="user-search" method="GET" action="{{ route('admin.users.index') }}">
                    <label class="sr-only" for="search">名前またはメールアドレスで検索</label>
                    <input class="question-input" id="search" name="search" type="search" value="{{ $search }}" placeholder="名前・メールアドレス">
                    <button class="secondary-action" type="submit">検索</button>
                    @if ($search !== '')<a class="text-link" href="{{ route('admin.users.index') }}">解除</a>@endif
                </form>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr><th>ユーザー</th><th>権限</th><th>状態</th><th>登録日</th><th></th></tr>
                        </thead>
                        <tbody>
                            @forelse ($users as $user)
                                <tr>
                                    <td><strong>{{ $user->name }}</strong><span>{{ $user->email }}</span></td>
                                    <td>{{ $user->isAdmin() ? 'Admin' : 'User' }}</td>
                                    <td><span class="status-label {{ $user->is_active ? 'is-active' : 'is-inactive' }}">{{ $user->is_active ? '利用中' : '停止中' }}</span></td>
                                    <td>{{ $user->created_at->format('Y/m/d') }}</td>
                                    <td><a class="table-link" href="{{ route('admin.users.show', $user) }}">詳細</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="empty-state">該当するユーザーはいません。</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $users->links() }}
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
