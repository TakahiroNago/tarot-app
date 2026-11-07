# VPS 構築・運用設計（iroca）

本書は2026年11月7日時点の実際の構成と、未対応分の設計をまとめたもの。実際の構成は、運用者の記録と読み取り専用の確認結果に基づく。「案」と書いた設定は、まだ適用も実機検証もしていない。

**同じVPSでZENが稼働している。** ZENのNginx、PHP-FPM、DB、環境変数は変更しない。本番を変更する前に、内容と影響を説明して承認を得る。`EC_ENABLED=false`は、公開の指示があるまで変更しない。

## 1. 前提

| 項目 | 内容 |
|---|---|
| サーバー | ConoHa VPS、Ubuntu 24.04、メモリ4GB。**ZENと同居** |
| 公開URL | `https://iroca.art-full-stack.com`（Cloudflareプロキシ経由） |
| 配置先 | `/var/www/ec`（所有者`ec-deploy`）。リリースディレクトリは使っていない |
| アプリ | このリポジトリ一式。タロット機能も公開し、ECは`EC_ENABLED`で別に制御する |
| ミドルウェア | Nginx、PHP 8.4（FPM）、**MySQL 8.0.46** |
| 配備済みコミット | `b702ec4`（migration 24件、テーブル34個） |
| 時刻 | OSは日本時間。`APP_TIMEZONE`は`Asia/Tokyo`だが、UTCとどちらにするか**未決定**（9章） |

## 2. 全体構成

```
利用者 ─HTTPS→ Cloudflare ─HTTPS→ Nginx（iroca、zen、default）
Stripe ─Webhook→                    ├→ PHP-FPM ec（画面、Webhook、占い）→ MySQL `ec`
                                    └→ PHP-FPM ZENのプール             → MySQL `zen`

root cron（毎日2:30）→ 暗号化バックアップ → Cloudflare R2
未設定：キューワーカー、スケジューラー（7章）
```

- VPS、OS、Nginx本体、PHP-FPM本体、MySQLサーバーをZENと共有する。どれかを再起動、更新、設定変更すると両方に影響する。
- メモリはZENと共有するので、以前の2GB向けの配分は使わない。ワーカーの追加後に`free -m`で実測する。

## 3. OSの初期設定

- `ec-deploy`がアプリの実行と配備を兼ねる。`zen-deploy`とは別のユーザー。
- 書き込み先は`storage`と`bootstrap/cache`。
- SSH、ファイアウォール、`fail2ban`、`unattended-upgrades`は記録がなく、未確認。

## 4. Nginx

| サイト | 内容 |
|---|---|
| `iroca` | HTTPをHTTPSへ転送。Let's Encrypt証明書 |
| `zen` | 変更しない |
| `default` | 未知のHostを444で拒否 |

変更はIROCA専用の設定に限り、`nginx -t`のあとで`systemctl reload nginx`を実行する。

### Hostヘッダーの防御

パスワード再設定と会員登録確認のメールは、Webリクエストの中でリンクを作る。偽のHostを受け入れると、正規のメールのリンク先が攻撃者のサイトになり、再設定トークンを盗まれる。

| 層 | 内容 |
|---|---|
| Nginx | `default`サイトが未知のHostを拒否する |
| アプリ | `bootstrap/app.php`の`trustHosts`が`iroca.art-full-stack.com`のみ許可（サブドメインも不可） |
| リンク生成 | 本番環境では`APP_URL`をルートURLに固定し、`https`を強制する |

**`APP_ENV=local`では`trustHosts`が無効になる。** 本番は`production`であることを確認済み。

VPS上で確認した結果（2026年11月7日、Cloudflareを通さない`127.0.0.1`宛て）。

| 要求 | 結果 |
|---|---|
| 正規のHostで`/up` | 200 |
| 正規のSNIに偽のHost（443番） | **400** |

偽のHostは拒否できている。ただし`default`サイトの444ではないので、応答元がNginxかLaravelか、どのサイトに届いたかは未確認（16章）。

### 接続元IPの復元（案、未適用）

Cloudflare経由では、Nginxが見る接続元はCloudflareになる。`real_ip`の設定がなく、アプリにも信頼プロキシの設定がないので、IP単位の回数制限（ログイン5回/分など）を複数の訪問者で共有する可能性がある。

`iroca`の`server`ブロックにのみ追加する。

```nginx
# https://www.cloudflare.com/ips/ の全範囲（IPv4、IPv6）を1行ずつ書く
set_real_ip_from <Cloudflareの範囲>;
real_ip_header CF-Connecting-IP;
```

- Cloudflare以外から直接接続された場合は、このヘッダーを使わないので偽装できない。
- Cloudflareの範囲が変わったら更新が必要。
- 適用後、異なる回線からのアクセスで`iroca`のアクセスログに実際のIPが記録されること、回数制限が接続元ごとに働くことを確認する。

## 5. PHP 8.4 / PHP-FPM

`/etc/php/8.4/fpm/pool.d/ec.conf`：ユーザー`ec-deploy`、ソケット`/run/php/php8.4-fpm-ec.sock`、`pm=ondemand`、`pm.max_children=5`、`pm.max_requests=500`、`clear_env=yes`。

**占いと決済Webhookが同じプールを使う。** 占いはOpenAIを呼ぶ間ワーカーを占有する（既定の待ち時間は1回30秒で、1回の占いに2回呼ぶ）。占いが5件重なると、WebhookもWebの画面も待たされる。Webhookを取りこぼした場合は定期照合で回復するが、それにはスケジューラーが必要（7章）。公開前に、占い専用プールの要否を負荷から判断する。

変更後は`php-fpm8.4 -tt`で確認する。ZENのプールは変更しない。

## 6. MySQL 8.0

- DB`ec`、ユーザー`ec_user`@`127.0.0.1`、権限は`ec.*`のみ。接続はTCP。
- 並行テストとCIは本番と同じ`mysql:8.0.46`で実行する（10章）。本番を更新するときは、`.github/workflows/ci.yml`と`tests/Support/Concurrency/ConcurrencyHarness.php`も合わせて変更する。
- **サーバーの設定とバージョンはZENと共有する。** `my.cnf`の変更、再起動、8.4への更新は、ZENの影響確認と承認を得てから行う。
- `log_error_verbosity`が既定の2だと、デッドロックの記録はエラーログに残らない。InnoDBの`lock_deadlocks`カウンタでは確認できる。本番の値は未確認で、サーバー全体の設定なので変更しない。
- DROP、TRUNCATE、初期化、SQLインポート、権限変更は、対象DBを確認し、明示の承認を得てから行う。

## 7. キューとスケジューラー（案、未設定）

**2026年11月7日の確認では、サービス、timer、`ec-deploy`のcronのいずれも存在しない。** このままでは次の処理が動かない。

- 確認メールなどのキュー処理
- Webhookを取りこぼした注文の定期照合。注文は`pending_payment`のまま残る
- 期限切れの在庫予約の解放、在庫監査、カート掃除

`/etc/systemd/system/iroca-queue.service`

```ini
[Unit]
Description=iroca queue worker
After=mysql.service

[Service]
User=ec-deploy
WorkingDirectory=/var/www/ec
ExecStart=/usr/bin/php artisan queue:work database --sleep=3 --tries=3 --timeout=60 --max-time=3600 --memory=128
Restart=always
RestartSec=5
MemoryMax=192M

[Install]
WantedBy=multi-user.target
```

`iroca-schedule.service`（`Type=oneshot`、`WorkingDirectory=/var/www/ec`、`ExecStart=/usr/bin/php artisan schedule:run`、`User=ec-deploy`）と`iroca-schedule.timer`（`OnCalendar=minutely`、`AccuracySec=1s`）。

- 適用前に`php artisan config:show queue.default`が`database`であることを確認する。
- 実行内容は定期照合（毎分）、在庫監査（03:00）、カート掃除（03:30）。時刻は`APP_TIMEZONE`に従う。
- 停止に気づけるよう、どちらも監視対象にする。

## 8. SSL

- Cloudflareはプロキシ有効、SSLモードはFull (strict)。
- オリジンはLet's Encrypt証明書で、Certbotが自動更新する。
- Stripeの本番Webhookを登録するときに、CloudflareのWAFやボット対策に遮断されないことを確認する。

## 9. 本番の環境変数（値は`/var/www/ec/.env`にのみ置く）

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://iroca.art-full-stack.com  # メールのリンクはこの値で作られる
APP_TIMEZONE=Asia/Tokyo                   # 未決定（下記）
EC_ENABLED=false
DB_DATABASE=ec
DB_USERNAME=ec_user
SESSION_COOKIE=iroca-session
SESSION_DOMAIN=null                       # 親ドメインへCookieを渡さない
```

ほかに`APP_KEY`、`DB_PASSWORD`、`EC_STRIPE_SECRET`、`EC_STRIPE_WEBHOOK_SECRET`、`OPENAI_API_KEY`、`GOOGLE_*`、`MAIL_*`、`CONTACT_EMAIL`が必要。メール送信は未確認。

### `APP_TIMEZONE`の選択（未決定）

| 値 | 無料回数と日次占いの切り替わり | 必要な対応 |
|---|---|---|
| `UTC` | 日本時間の朝9時 | 本番の`.env`を変更し、キャッシュを作り直す。テストはそのまま使える |
| `Asia/Tokyo` | 日本時間の0時 | テスト4件と本書の修正 |

`Asia/Tokyo`で全テストを実行した結果は685件中681件成功。失敗は、UTCであること自体を確認するテスト2件と、UTCの時刻をそのまま保存するテスト2件。保存時刻が変わるので、実データが入る前に決める。

## 10. CI/CD（GitHub Actions）

### CI：PRごとに自動実行（`.github/workflows/ci.yml`）

1. PHP 8.4をセットアップし、`composer install`、`vendor/bin/pint --test`
2. Node 22で`npm ci && npm run build`（機能テストがViteのマニフェストを使うので、テストより先に行う）
3. 使い捨ての`APP_KEY`を生成し、`vendor/bin/pest`（SQLite）
4. 本番と同じ`mysql:8.0.46`を`docker run`で`127.0.0.1:33307`に起動し、`vendor/bin/pest -c phpunit.mysql.xml`
5. InnoDBの`lock_deadlocks`カウンタが0であることを確認する（アプリが再試行するので、テストが成功してもデッドロックが起きている場合がある）

Actionはコミットのハッシュで固定する。

### 配備

`/usr/local/sbin/deploy-ec`があるが、内容とGitHub Actionsとの連携は未確認。自動配備のワークフローはリポジトリにない。使う前に内容を確認し、少なくとも次を満たすようにする。

- 配備前にバックアップを取得する。
- `php artisan migrate --force`のあとで`php artisan optimize`を実行する。
- FPMの再読み込みはZENにも影響するので、方法を確認する。
- `php artisan queue:restart`を実行する（7章の適用後）。
- `https://iroca.art-full-stack.com/up`を確認する。

`APP_KEY`は再生成しない。migrationは旧コードでも動く形（追加中心）にする。

## 11. 自動バックアップ

| 項目 | 内容 |
|---|---|
| 対象 | DB`ec`のダンプ、`/var/www/ec/storage/app` |
| 方法 | root cronで毎日2:30に`/usr/local/sbin/backup-iroca`を実行。resticで暗号化してCloudflare R2へ保存 |
| 保持 | 日次7、週次4、月次12 |
| 通知 | Healthchecks.ioへ成功・失敗を通知 |
| ログ | `/var/log/iroca-backup.log`（30日分） |

- 2026年11月7日に手動実行と一時ディレクトリへの復元を確認した。本番DBへの復元は未実施。
- cronによる次回の実行は未確認。
- 古いランダムパス方式のスナップショットは、安定運用を確認してから整理する。
- `.env`はバックアップ対象外。安全な保管と復旧手順は未整備。
- 認証情報の実際の値は、VPSのroot専用ファイルとパスワードマネージャーにのみ置く。

## 12. 復元・ロールバック手順

| 状況 | 対応 |
|---|---|
| 購入を今すぐ止めたい | `.env`を`EC_ENABLED=false`にして`php artisan optimize`を実行する。FPMの再読み込みが必要な場合はZENへの影響を確認する |
| 配備後に不具合 | 手順は未整備。`deploy-ec`の確認後に決める |
| migrationに問題 | 修正版を前進で配備する。`migrate:rollback`は検証済みの場合のみ |
| データ破損 | 下記のDB復元 |
| VPS全損 | 新しいVPSに構築し、下記のDB復元。**ZENの復旧も同時に必要** |

### DB復元

1. `EC_ENABLED=false`にし、キューとスケジューラーを止める。
2. 現状のダンプを別に保存する。
3. resticのスナップショットを一時ディレクトリへ復元し、別名のDB（例：`ec_restore`）へ入れて内容を確認する。別名のDBは`ec_user`の権限の外なので、作成は承認を得てから管理者が行う。
4. 問題なければ本番DBへ切り替える。**`zen`には触れない。**
5. **Stripeと照合する。** 復元地点より後の決済はDBにない。Dashboardの支払いと注文を突き合わせ、不足分を確認する。バイナリログの保管は未設定なので、最大で約1日分の差が出る。
6. キューとスケジューラーを再開する。Webhookの再送はDashboardから行う。
7. 確認後に`EC_ENABLED`を元に戻す。

### 公開前の練習（必須）

別のDBへ復元して件数を確認する。不具合時の戻し方を試す。Webhookの再送を試す。

## 13. 秘密情報の管理

| 秘密情報 | 置き場所 | 管理 |
|---|---|---|
| DBパスワード | `.env`、パスワードマネージャー | 外部へ公開しない |
| Stripe本番キー | `.env`のみ | 制限付きキー（`rk_live_`）を推奨。Checkoutと返金に必要な権限に絞り、構築時に動作確認する。リポジトリ、GitHub、チャットに置かない |
| Webhookシークレット | `.env`のみ | Dashboardで再発行可能 |
| `APP_KEY` | `.env`、パスワードマネージャー | **再生成しない。** ゲスト注文リンクと決済の再試行データが無効になる |
| resticのパスワード、R2の認証情報、Healthchecks URL | VPSのroot専用ファイル、パスワードマネージャー | 再生成しない。紛失するとバックアップを復号できない |

- `bootstrap/cache/config.php`にも秘密情報が入るので、公開しない。
- 漏えい時はキーを再発行し、`.env`を更新して`php artisan optimize`を実行する。手順を別途用意する。

## 14. EC停止中の動作（確認済み）

テストとローカルで起動したアプリでの確認結果。VPSでは未確認。

| 項目 | 動作 |
|---|---|
| 商品・カート・注文手続き | 404 |
| 支払い完了後の画面 | **開ける。** `session_id`が注文と一致する場合のみ。決済状態はサーバーの記録のみで判定する |
| 注文履歴 | **開ける。** 本人の注文のみ。メニューは注文がある会員にだけ表示。全住所を出す注文詳細はキャッシュ禁止 |
| ゲストリンク | **開ける。** 署名、DBのハッシュ照合、30日の期限、回数制限あり。表示は国、都道府県、市区町村までで、宛名、郵便番号、番地、建物名は出さない |
| 注文データ、ゲストリンクの署名 | 変更なし |
| ゲストリンクの有効期限（30日） | 停止中も進む |
| 停止前に開いた決済画面 | Stripe上で支払える。Webhookで`paid`になり、在庫も確定する |
| 確認メール | 送信される。メール内のリンクも開ける |
| 決済画面からのキャンセルの戻り先 | 404（注文手続きの一部のため） |
| お支払い待ちの注文 | 購入者は再開も取り消しもできない。期限切れで解放される |
| Webhook、定期照合 | 動作する |
| 管理画面（発送、返金） | 動作する |
| 商品画像 | ファイル名を知っていれば**取得できる**。推測した名前は404、一覧は不可 |

## 15. ZENとの分離

| 項目 | 状態 |
|---|---|
| VPS、OS、Nginx本体、PHP-FPM本体、MySQLサーバー | **共有** |
| Nginxのサイト、FPMプール、DB、DBユーザー、配備ユーザー、ソース | 別 |
| Stripe | EC専用アカウント |
| Cookie | ホスト限定（`SESSION_DOMAIN`なし）。親ドメインへ送らない |
| バックアップ | IROCAは専用のR2バケット。ZENの扱いは本書の対象外 |

共有部分は片方の障害や高負荷がもう片方に及ぶ。共有部分の変更は、IROCA専用の設定で実現できないかを先に検討する。DNSの変更は`iroca`のサブドメインに限り、親ドメインのSPF等を変更しない。

## 16. 公開までに残る作業

優先順に並べる。

1. `APP_TIMEZONE`の決定（9章）
2. キューワーカーとスケジューラーの設定（7章）
3. 接続元IPの復元（4章）
4. 偽のHostへの400の応答元の確認（4章）
5. メール送信の設定と疎通確認
6. テストモードのStripeで通しテスト（Webhook、照合、返金、メール）
7. `.env`の保管と復旧手順、バックアップのcron実行の確認、復元の練習（11、12章）
8. `deploy-ec`の確認と、配備・戻し方の手順（10、12章）
9. 負荷の実測、占い専用プールの判断（5章）
10. 法務・販売条件が揃ったら本番Stripeへ切り替え、`EC_ENABLED=true`
