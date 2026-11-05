# 既存システム調査結果

## 調査範囲と制約

ソース、設定、migration、画面、テスト、README、依存マニフェストを読み取り調査した。作業制限に従い、アプリのコード・環境設定・migration・依存関係は変更せず、DB接続、テスト実行、本番接続も行っていない。DBの現況は確認しておらず、以下のDB情報はmigrationで定義されたスキーマである。

## 1. Laravelのディレクトリ構成

| パス | 現状の用途 | EC追加時の方針 |
|---|---|---|
| `app/Http/Controllers` | Webコントローラー。Admin、Authのサブディレクトリあり | `Admin`配下の既存パターンに合わせてEC管理領域を分離 |
| `app/Http/Middleware` | 有効ユーザー、Admin、メール確認の制御 | 既存middlewareを再利用し、EC管理ルートに同じ保護を適用 |
| `app/Models` | `User`、`TarotReading` | ECモデルは責務ごとに追加。Userに注文情報を過剰に詰めない |
| `app/Services` | 占いクレジット、共有、Stripe等の処理 | 在庫・注文・チェックアウトをECサービスに分離 |
| `app/Mail` | 問い合わせメール | 注文確認用メールを追加し、送信失敗・再送を考慮 |
| `app/Support` | Tarotカードカタログ | 既存タロット支援コードは維持 |
| `routes` | `web.php`、`console.php` | 現在APIルートファイルなし。ECはWebルートに追加する案 |
| `database/migrations` | DBスキーマ履歴 | EC専用の新規migration群を追加する案。既存migrationは変更しない |
| `resources/views` | Blade画面、`admin/users`等 | `shop/`、`cart/`、`checkout/`、`admin/products`、`admin/orders`等で分離 |
| `resources/css`, `resources/js` | Viteエントリ | 既存のデザイン・ヘッダーに影響する変更は限定し、必要なUIだけ追加 |
| `tests/Feature`, `tests/Unit` | Pestの機能・単体テスト | ECはFeature中心、在庫・料金計算はUnit/Featureで検証 |
| `config` | DB、Storage、Mail、Stripe等 | 既存値を書き換えず、決定したプロバイダーに必要な設定を別途設計 |

`.ai/rules`および`.github/workflows`は確認できなかった。ルートの`AGENTS.md`と`CLAUDE.md`にはLaravel Boost等の作業規約が記載されている。デプロイ関連の確定構成はREADME以外に確認できなかった。

## 2. Models・Controllers・Services

### Models

- `User`: 標準的なEloquent認証モデル。`MustVerifyEmail`、`isAdmin()`、`is_active`を持つ。`role`は`user`/`admin`。占い履歴との`hasMany`がある。プラン・占いクレジット・Google ID・Stripe顧客/サブスクリプション関連属性も既にある。
- `TarotReading`: `user_id`、質問、カード、解釈、任意のカテゴリ・要約、任意の日次占い日を持つ。ユーザーとの`belongsTo`がある。
- EC用のモデル、注文、商品、カートは存在しない。

### 主なControllers・Services

- `TarotController`: 占いの生成、日次占い、カード選択、OpenAI呼び出し、共有URL生成、ログイン/ゲスト別保存を担当。
- `ReadingHistoryController`、`SharedReadingController`: ログインユーザーの履歴・セッション中の履歴・有効期限付き共有結果を表示。
- `SessionReadingPersister`: 登録・ログイン後にセッション上のゲスト占い結果をDBへ移行。
- `ReadingCreditService`、`GuestTrialCreditService`: トランザクションやロックを利用して占い回数を管理。
- `StripeBillingService`、`StripeWebhookController`: Stripe顧客・既存クレジット購入のWebhook基盤。WebhookイベントIDの記録はあるが、ECイベント処理は未実装。
- `Admin\UserController`: 既存Admin管理。DBトランザクションとロックで最後の有効Adminの変更を制御。
- `AccountSettingsController`: アカウント削除時にユーザー、関連占い等を削除する。EC注文を同じカスケード削除にすると履歴・法定記録が失われるため要設計。

## 3. Routes・API

`routes/web.php`にトップ、タロット、履歴、共有、認証、アカウント、Adminユーザー管理、問い合わせ、Stripe Webhookのルートがある。`bootstrap/app.php`はWebとコンソールルートを登録し、`/up`ヘルスチェックを定義する。API用ルートファイル・APIルート登録はない。

Adminは`auth`、`active`、`verified`、`admin`のmiddlewareグループ。Stripe WebhookはCSRF除外対象であり、署名検証はControllerで行われる。既存EC導入では商品閲覧・ゲスト購入ルートをゲストに開き、管理ルートだけ既存Admin制御に載せる案。

## 4. DBテーブル・リレーション（migration定義）

### 現行テーブル

| テーブル | 主な構成・関係 |
|---|---|
| `users` | 基本認証情報、role/is_active、plan/credits、Google ID、Stripe顧客・サブスクリプション属性。email、Google ID、Stripe ID群にunique制約 |
| `password_reset_tokens` | emailをキーとするパスワード再設定 |
| `sessions` | セッションストレージ用。user_idはnullable |
| `cache`, `cache_locks` | DBキャッシュ/ロック用 |
| `jobs`, `job_batches`, `failed_jobs` | DBキュー用 |
| `tarot_readings` | `user_id`からusersへFK。ユーザーと作成日時のindex、`user_id`と`daily_reading_date`のunique制約 |
| `guest_trial_credits` | `device_hash`を主キーにゲスト利用回数を保存 |
| `legacy_guest_trial_ip_credits` | 旧IPベースのゲスト利用回数テーブル。migrationで名称変更された履歴あり |
| `stripe_credit_purchases` | user_id、Checkout Session ID、商品キー、価格ID、クレジット数、金額、通貨 |
| `stripe_webhook_events` | Stripe event ID unique、type、processed_at |

`tarot_readings`は`question`、`card`、`interpretation`に加え、nullableな`category`、`summary`、`daily_reading_date`を持つ。`daily_reading_date`は同一ユーザー同日一件の一意制約対象。

`users`テーブルの実際の適用状態、MySQLのバージョン、制約の本番反映状況はDB未接続のため未確認。migrationとDBの差分確認が実装開始時に必要。

## 5. 認証・権限

- Webセッション認証、通常登録/ログイン、Google Socialiteログイン、メールアドレス確認が存在する。
- 登録アカウントのroleはサーバー側で`user`固定。Adminは`isAdmin()`と`EnsureAdmin`で判定。
- 停止ユーザーは`EnsureActiveUser`でログアウト・セッション破棄される。
- グローバルWeb middlewareは、メール未確認のログインユーザーを多くのWeb画面から確認画面へ誘導する。ゲスト購入は可能だが、ログイン中未確認ユーザーのEC画面/完了画面で影響が出ないかルート設計が必要。
- 既存Userモデルを注文所有者に使うことは可能。ただし、ゲスト注文のため`orders.user_id`はnullableが必要。User削除時は注文削除でなく匿名化/NULL化を検討する。

## 6–7. タロット生成、保存、再表示

### 通常の占い

1. `POST /tarot/draw`で質問を検証。
2. ユーザーIDまたはゲストデバイストークンに対してCache lockを取得。
3. ユーザーはクレジット、ゲストはゲスト試用回数を消費。
4. カードをランダムに選び、OpenAIで解釈とメタデータを生成。
5. ログインユーザーは`tarot_readings`へ保存。ゲストは`tarot_readings`セッションへ保存。
6. 公開共有サービスは個人識別子を含めず、共有結果をCacheに30日保存してランダムtokenを発行。
7. OpenAI処理等に失敗した場合、消費したクレジットを返却する。

### 日次占い・履歴

- `POST /tarot/daily`はログインユーザーに限定し、日付ごとのDB行があれば再利用。新規生成時は`daily_reading_date`で一意に保存する。
- `/readings`はログインユーザーの最近3か月・最大50件を表示し、セッション結果も併記する。詳細は所有ユーザーまたは同じセッションのIDで照会する。
- 登録/ログイン/Googleログイン成功時に`SessionReadingPersister`がゲスト結果をユーザーのDBへ移し、セッションから削除する。
- 共有表示は期限付きCacheから読み、個人アカウント情報を表示しない。

### EC推薦との接続点

推薦商品は結果生成時に一度選び、ログイン利用者は専用関連テーブル、ゲストは現在のセッションデータと共有Cacheに選択結果を保持する案。履歴の再表示時に毎回ランダム抽選をしない。商品を販売停止・在庫切れにした後の過去結果表示は「同じ商品を示すが購入不可」とするか、推薦枠自体を隠すか要決定。

## 8. 既存画面構成

Bladeでトップ、占い入力、占い結果、履歴一覧/詳細/共有、認証、アカウント設定、Adminユーザー管理、問い合わせ、規約・プライバシー・特商法表示を構成。共通ヘッダー/フッターをincludeし、Vite経由でCSS/JSを読んでいる。Adminと公開画面に共通ナビゲーションがあるため、ECナビ追加は両者を考慮する。

特定商取引法表示画面は存在するが、現在は有料サービス/物販を提供していない旨のテンプレート記述がある。EC公開前に実際の物販内容に合わせて更新が必要。

## 9. テスト構成

- Pest 5系、FeatureとUnitに分割。Featureテストは`RefreshDatabase`を用い、`phpunit.xml`ではSQLite in-memory、Cache/Sessionはarray、Mailはarray、Queueはsyncに設定。
- 認証、Admin、占い履歴・セッション移行、クレジット、Stripe署名/重複イベント記録、問い合わせ、法的ページ等をテスト。
- 占いテストはOpenAIをfakeし、ゲスト結果がDBへ書かれないこと、ユーザー所有範囲、日次重複抑止、共有表示等を確認。
- 今回はテストを実行していない（DB接続を含み得るため）。ECテストの具体項目は[実装計画](./07-implementation-plan.md)に記載。

## 10. CI/CD・デプロイ

`.github/workflows`や他のCI/CD定義は確認できなかった。READMEはローカルセットアップと、配備時に専用DB、session/cache/queue、storage、domain、provider credentialsを構成する必要を記載するが、実際のデプロイ先・リリース手順・本番DBは不明。既存環境にEC用Storage/CDN、キュー、メール、Webhook URL、バックアップ、監視があるか実装前に確認する。

## 11. 衝突・影響可能性

1. `User`削除のcascadeが占い履歴を削除するため、EC注文をUserにcascadeすると注文・監査証跡も失われる。注文側FKは`nullOnDelete`とし、PII匿名化/保管要件を別途決める。
2. `StripeWebhookController`と`StripeBillingService`は既存クレジット購入用。EC処理を同じサービスへ直接混在させるとイベント処理や再送で既存購入に影響し得る。イベント振り分けとトランザクション境界を明確にし、現行のクレジット購入テストを回帰させる。
3. Webグループの未確認メール制御は、ログイン中の購入者に影響する可能性がある。ゲスト導線、確認メールへの誘導、決済Webhook（CSRF除外）を分けて検証する。
4. 共通ヘッダーはログインユーザーの占いクレジット残高を取得する。ECページでも動作するが、独立性、ページ応答時間、ログイン停止状態の挙動を確認する。
5. `/readings/{reading}`など既存ルートとECパス、`admin.*`のルート名/URLを衝突させない。
6. Cache lockと在庫ロックは別の競合制御である。Cacheのドライバーが複数Web worker間の原子的ロックを保証する構成か確認し、EC在庫の正確性はDBの制約・トランザクションで担保する。
7. 商品画像は現在のStorage設定を利用可能だが、公開diskのURL、バックアップ、容量、画像形式/サイズ制限、配信方式は未確定。
8. タロット結果表示への推薦挿入は結果テンプレート、履歴詳細、共有詳細の複数箇所に波及する。占いサービスの必須依存にせず、商品がない場合にも従来画面を保つ。

## 12. セキュリティ上の論点

詳細は[セキュリティ設計](./06-security-design.md)。特に重要なのは、Admin認可、ゲストカート所有確認、注文/決済の金額照合、Webhook署名と冪等性、同時在庫更新、住所・連絡先の漏えい防止、画像アップロード検証、ログ/メールの個人情報削減、アカウント削除時の注文保持方針である。
