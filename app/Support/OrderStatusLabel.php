<?php

namespace App\Support;

use App\Models\Order;

/**
 * Customers see these pages, so a value without a label shows a neutral word, never the internal one.
 */
final class OrderStatusLabel
{
    public const ORDER = [
        'pending_payment' => 'お支払い待ち',
        'paid' => '受付済み',
        'processing' => '発送準備中',
        'shipped' => '発送済み',
        'completed' => '配送完了',
        'cancelled' => 'キャンセル',
        'payment_review' => 'ご注文内容の確認中',
        'refund_pending' => '返金手続き中',
        'refunded' => '返金済み',
    ];

    public const PAYMENT = [
        'pending' => 'お支払い待ち',
        'paid' => 'お支払い済み',
        'failed' => 'お支払い未完了',
        'cancelled' => 'キャンセル',
        'payment_review' => 'お支払いの確認中',
        'refund_pending' => '返金手続き中',
        'refund_review' => '返金の確認中',
        'refunded' => '返金済み',
    ];

    public const SHIPMENT = [
        'pending' => '発送準備中',
        'shipped' => '発送済み',
        'delivered' => '配送完了',
    ];

    private const UNKNOWN = '確認中';

    private const EVENTS = [
        'terms_accepted' => '規約への同意',
        'customer_cancelled' => '購入者による取り消し',
        'review_opened' => '支払いレビュー開始',
        'review_approved' => '支払いレビュー承認',
        'payment_reconciled' => '定期照合で決済を反映',
        'duplicate_payment_detected' => '二重決済を検知',
        'partial_refund_detected' => '部分返金を検知',
        'refund_requested' => '返金の依頼',
        'refund_pending' => '返金の処理待ち',
        'refund_request_failed' => '返金の依頼に失敗',
        'refund_completed' => '返金完了',
        'refund_failed' => '返金失敗',
        'shipment_updated' => '発送情報の更新',
    ];

    private const REASONS = [
        Order::REVIEW_AMOUNT_MISMATCH => '金額の不一致',
        Order::REVIEW_CURRENCY_MISMATCH => '通貨の不一致',
        Order::REVIEW_LATE_PAYMENT => '予約解放後の支払い',
        Order::REVIEW_INVENTORY_INCONSISTENT => '在庫の不整合',
        Order::REVIEW_VARIANT_MISSING => '商品の参照なし',
        'stripe_error' => 'Stripeへの接続エラー',
        'amount_mismatch' => '金額の不一致',
    ];

    public static function order(?string $status): string
    {
        return self::ORDER[$status] ?? self::UNKNOWN;
    }

    public static function payment(?string $status): string
    {
        return self::PAYMENT[$status] ?? self::UNKNOWN;
    }

    public static function shipment(?string $status): string
    {
        return self::SHIPMENT[$status] ?? self::UNKNOWN;
    }

    /**
     * Only administrators read the history, and an unlisted value there is worth seeing as it is.
     */
    public static function event(string $eventType): string
    {
        return self::EVENTS[$eventType] ?? $eventType;
    }

    /**
     * Only administrators read reasons, and an unlisted value there is worth seeing as it is.
     */
    public static function reason(?string $reason): string
    {
        return self::REASONS[$reason] ?? (string) $reason;
    }
}
