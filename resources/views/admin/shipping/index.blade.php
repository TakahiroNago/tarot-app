<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>送料・税設定 | 月詠 TAROT JOURNAL</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div class="page-frame">
        @include('partials.site-header')
        <main class="page-content admin-content">
            <section class="admin-panel">
                <p class="eyebrow">SHIPPING & TAX</p>
                <h1>送料・税設定</h1>
                @if (session('status'))<p class="form-notice" role="status">{{ session('status') }}</p>@endif
                @if ($errors->any())
                    <div class="admin-errors" role="alert">
                        @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
                    </div>
                @endif
                <p class="form-notice">国・地域、送料、税の徴収義務と税率は、事業者・専門家の確認後に登録してください。税ルールまたは送料がない国ではチェックアウトを停止します。</p>

                <section class="shipping-admin-section">
                    <h2>配送方法</h2>
                    <form class="shipping-method-create" method="POST" action="{{ route('admin.shipping.methods.store') }}">
                        @csrf
                        <label class="question-label" for="method-code">コード</label>
                        <input class="question-input" id="method-code" name="code" type="text" maxlength="40" pattern="[A-Za-z0-9_-]+" required>
                        <label class="question-label" for="method-name">名称</label>
                        <input class="question-input" id="method-name" name="name" type="text" maxlength="120" required>
                        <button class="primary-action" type="submit">配送方法を追加</button>
                    </form>

                    @foreach ($shippingMethods as $method)
                        <div class="shipping-method-row">
                            <form method="POST" action="{{ route('admin.shipping.methods.update', $method) }}">
                                @csrf
                                @method('PATCH')
                                <span class="shipping-method-code">{{ $method->code }}</span>
                                <label class="sr-only" for="method-name-{{ $method->id }}">配送方法名</label>
                                <input class="question-input" id="method-name-{{ $method->id }}" name="name" type="text" maxlength="120" value="{{ $method->name }}" required>
                                <input type="hidden" name="is_active" value="0">
                                <label class="shop-checkbox"><input type="checkbox" name="is_active" value="1" @checked($method->is_active)> 有効</label>
                                <button class="secondary-action" type="submit">更新</button>
                            </form>
                            <p class="settings-note">登録済み送料: {{ $method->rates->count() }}件</p>
                        </div>
                    @endforeach
                </section>

                <section class="shipping-admin-section">
                    <h2>国別固定送料（JPY）</h2>
                    <form class="shipping-rate-create" method="POST" action="{{ route('admin.shipping.rates.store') }}">
                        @csrf
                        <label class="question-label" for="rate-method">配送方法</label>
                        <select class="question-input" id="rate-method" name="shipping_method_id" required>
                            <option value="">選択してください</option>
                            @foreach ($shippingMethods->where('is_active', true) as $method)
                                <option value="{{ $method->id }}">{{ $method->name }}</option>
                            @endforeach
                        </select>
                        <label class="question-label" for="rate-country">ISO国コード</label>
                        <input class="question-input" id="rate-country" name="country_code" type="text" maxlength="2" pattern="[A-Z]{2}" placeholder="JP" required>
                        <label class="question-label" for="rate-fee">送料（円）</label>
                        <input class="question-input" id="rate-fee" name="fee_minor" type="number" min="0" max="1000000000" required>
                        <button class="primary-action" type="submit">送料を追加</button>
                    </form>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead><tr><th>方法</th><th>国</th><th>送料</th><th>有効</th><th>更新</th></tr></thead>
                            <tbody>
                                @foreach ($shippingMethods as $method)
                                    @foreach ($method->rates as $rate)
                                        <tr>
                                            <td>{{ $method->name }}</td>
                                            <td colspan="4">
                                                <form class="shipping-rate-row" method="POST" action="{{ route('admin.shipping.rates.update', $rate) }}">
                                                    @csrf
                                                    @method('PUT')
                                                    <label class="sr-only" for="rate-country-{{ $rate->id }}">ISO国コード</label>
                                                    <input class="question-input table-input" id="rate-country-{{ $rate->id }}" name="country_code" type="text" maxlength="2" pattern="[A-Z]{2}" value="{{ $rate->country_code }}" required>
                                                    <label class="sr-only" for="rate-fee-{{ $rate->id }}">送料（円）</label>
                                                    <input class="question-input table-input" id="rate-fee-{{ $rate->id }}" name="fee_minor" type="number" min="0" max="1000000000" value="{{ $rate->fee_minor }}" required>
                                                    <input type="hidden" name="is_active" value="0">
                                                    <label class="shop-checkbox"><input type="checkbox" name="is_active" value="1" @checked($rate->is_active)> 有効</label>
                                                    <button class="secondary-action" type="submit">更新</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                @endforeach
                                @if ($shippingMethods->sum(fn ($method): int => $method->rates->count()) === 0)
                                    <tr><td class="empty-state" colspan="5">国別送料は未登録です。</td></tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="shipping-admin-section">
                    <h2>国別税ルール</h2>
                    <p class="settings-note">有効期間は日本時間の日付で判定します。開始日の0時に切り替わり、終了日の24時まで有効です。</p>
                    <p class="settings-note">税率はbasis pointで入力します（例: 10%は1000）。適用義務と税額計算は専門家が確認した値のみ登録してください。商品明細ごとに税込価格から税額を逆算し、1円未満を四捨五入します。</p>
                    <form class="tax-rate-create" method="POST" action="{{ route('admin.shipping.tax-rates.store') }}">
                        @csrf
                        <label class="question-label" for="tax-country">ISO国コード</label>
                        <input class="question-input" id="tax-country" name="country_code" type="text" maxlength="2" pattern="[A-Z]{2}" placeholder="JP" required>
                        <label class="question-label" for="tax-basis-points">税率（basis point）</label>
                        <input class="question-input" id="tax-basis-points" name="tax_basis_points" type="number" min="0" max="65535" required>
                        <label class="question-label" for="tax-active-from">適用開始日</label>
                        <input class="question-input" id="tax-active-from" name="active_from" type="date" required>
                        <label class="question-label" for="tax-active-until">適用終了日（任意）</label>
                        <input class="question-input" id="tax-active-until" name="active_until" type="date">
                        <input type="hidden" name="shipping_taxable" value="0">
                        <label class="shop-checkbox"><input type="checkbox" name="shipping_taxable" value="1"> 送料を課税対象にする</label>
                        <input type="hidden" name="is_active" value="0">
                        <label class="shop-checkbox"><input type="checkbox" name="is_active" value="1" checked> 有効</label>
                        <button class="primary-action" type="submit">税ルールを追加</button>
                    </form>
                    <div class="tax-rate-list">
                        @forelse ($taxRates as $taxRate)
                            <form class="tax-rate-row" method="POST" action="{{ route('admin.shipping.tax-rates.update', $taxRate) }}">
                                @csrf
                                @method('PUT')
                                <strong>{{ $taxRate->country_code }}</strong>
                                <label class="sr-only" for="tax-rate-{{ $taxRate->id }}">税率</label>
                                <input class="question-input table-input" id="tax-rate-{{ $taxRate->id }}" name="tax_basis_points" type="number" min="0" max="65535" value="{{ $taxRate->tax_basis_points }}" required>
                                <label class="sr-only" for="tax-from-{{ $taxRate->id }}">適用開始日</label>
                                <input class="question-input table-input" id="tax-from-{{ $taxRate->id }}" name="active_from" type="date" value="{{ $taxRate->active_from->format('Y-m-d') }}" required>
                                <label class="sr-only" for="tax-until-{{ $taxRate->id }}">適用終了日</label>
                                <input class="question-input table-input" id="tax-until-{{ $taxRate->id }}" name="active_until" type="date" value="{{ $taxRate->active_until?->format('Y-m-d') }}">
                                <input type="hidden" name="shipping_taxable" value="0">
                                <label class="shop-checkbox"><input type="checkbox" name="shipping_taxable" value="1" @checked($taxRate->shipping_taxable)> 送料課税</label>
                                <input type="hidden" name="is_active" value="0">
                                <label class="shop-checkbox"><input type="checkbox" name="is_active" value="1" @checked($taxRate->is_active)> 有効</label>
                                <button class="secondary-action" type="submit">更新</button>
                            </form>
                        @empty
                            <p class="history-empty">税ルールは未登録です。必要な国の税ルールが登録されるまで注文は確定できません。</p>
                        @endforelse
                    </div>
                </section>
                <a class="text-link" href="{{ route('admin.products.index') }}">商品管理に戻る</a>
            </section>
        </main>
        @include('partials.site-footer')
    </div>
</body>
</html>
