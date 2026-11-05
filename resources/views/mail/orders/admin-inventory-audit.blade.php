<p>在庫残高と台帳の合計に差異があります。在庫は自動で修正していません。原因を確認のうえ、管理画面の在庫調整で対応してください。</p>
<ul>
    @foreach ($differences as $difference)
        <li>商品バリエーション {{ $difference['variant_id'] }}: 在庫 {{ $difference['on_hand'] }}（台帳 {{ $difference['ledger_on_hand'] }}）、予約 {{ $difference['reserved'] }}（台帳 {{ $difference['ledger_reserved'] }}）</li>
    @endforeach
</ul>
