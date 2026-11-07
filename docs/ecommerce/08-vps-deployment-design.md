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
| 配備済みコミット | `c83a327`（2026年11月7日、migration 24件、テーブル34個） |
| 時刻 | OSと`APP_TIMEZONE`は日本時間（`Asia/Tokyo`、9章） |

## 2. 全体構成

```
利用者 ─HTTPS→ Cloudflare ─HTTPS→ Nginx（iroca、zen、default）
Stripe ─Webhook→                    ├→ PHP-FPM ec（画面、Webhook、占い）→ MySQL `ec`
                                    └→ PHP-FPM ZENのプール             → MySQL `zen`

root cron（毎日2:30）→ 暗号化バックアップ → Cloudflare R2
systemd：キューワーカー、スケジューラー（毎分）→ MySQL `ec`（7章）
```

- VPS、OS、Nginx本体、PHP-FPM本体、MySQLサーバーをZENと共有する。どれかを再起動、更新、設定変更すると両方に影響する。
- メモリはZENと共有するので、以前の2GB向けの配分は使わない。ワーカー追加後の実測は7章。

## 3. OSの初期設定

- `ec-deploy`がアプリの実行と配備を兼ねる。`zen-deploy`とは別のユーザー。
- 書き込み先は`storage`と`bootstrap/cache`。
- SSH、ファイアウォール、`fail2ban`、`unattended-upgrades`は記録がなく、未確認。

## 4. Nginx

| サイト | 内容 |
|---|---|
| `iroca` | HTTPをHTTPSへ転送。Let's Encrypt証明書 |
| `zen` | 変更しない |
| `default` | 80番のみ。未知のHostを444で拒否 |

変更はIROCA専用の設定に限り、`nginx -t`のあとで`systemctl reload nginx`を実行する。

### Hostヘッダーの防御

パスワード再設定と会員登録確認のメールは、Webリクエストの中でリンクを作る。偽のHostを受け入れると、正規のメールのリンク先が攻撃者のサイトになり、再設定トークンを盗まれる。

| 層 | 内容 |
|---|---|
| Nginx | 80番は`default`サイトが未知のHostを444で拒否する。**443番には既定サーバーがなく、最初に読み込まれる`iroca`が未知のHostも受ける** |
| アプリ | `bootstrap/app.php`の`trustHosts`が`iroca.art-full-stack.com`のみ許可（サブドメインも不可）。443番はここで拒否する |
| リンク生成 | 本番環境では`APP_URL`をルートURLに固定し、`https`を強制する |

**`APP_ENV=local`では`trustHosts`が無効になる。** 本番は`production`であることを確認済み。

VPS上で確認した結果（2026年11月7日、Cloudflareを通さない`127.0.0.1`宛て）。

| 要求 | 結果 |
|---|---|
| 正規のHostで`/up` | 200 |
| 正規のSNIに偽のHostで`/up` | 400 |
| 正規のSNIに偽のHostで`/forgot-password` | 400 |
| 偽のSNIと偽のHostで`/up` | 400（Symfonyのエラー画面） |
| 未知のSNIに返る証明書 | `iroca`の証明書 |

偽のHostは拒否できており、再設定リンクの偽装は起きない。400はLaravelの応答で、信頼しないHostの例外を400に変換し、400用の画面がないのでSymfonyのエラー画面になる。

未知のHostはPHPまで届き、`ec`プールのワーカーを使う。443番に既定サーバーを追加（`ssl_reject_handshake on`、Nginx 1.19.4以降）すればNginxで止められるが、ZENと共有する443番の設定に影響するので、使い捨てのコンテナで検証してから判断する（案、未適用）。

### 接続元IPの復元（2026年11月7日に適用）

Cloudflare経由では、Nginxが見る接続元はCloudflareになる。そのままでは、IP単位の回数制限（ログイン5回/分など）を複数の訪問者で共有してしまう。Nginxで接続元を訪問者のIPに置き換えるので、アプリに信頼プロキシの設定は要らない。

`/etc/nginx/snippets/iroca-cloudflare-realip.conf`に、https://www.cloudflare.com/ips/ の全範囲（2026年11月7日取得、IPv4 15件、IPv6 7件）を`set_real_ip_from`で並べ、`real_ip_header CF-Connecting-IP;`を書いた。`iroca`の443番の`server`ブロックで、`server_name`の直後に読み込む。80番（HTTPSへの転送と証明書の更新）とZENは変更していない。

```nginx
include snippets/iroca-cloudflare-realip.conf;
```

| 確認 | 結果 |
|---|---|
| IROCAの`/up`、ZENの`/` | 適用前と同じ（200、302） |
| PCとスマートフォンからのアクセス | 訪問者のIPが記録された（IPv4、IPv6） |
| `127.0.0.1`へ直接接続し、`CF-Connecting-IP`を偽装 | `127.0.0.1`が記録され、偽装は無視された |

- ファイアウォールは80番と443番への直接接続を許している。ヘッダーを使うのはCloudflareの範囲からの接続だけなので、直接接続ではIPを偽装できない。Cloudflare以外を遮断する設定はZENにも影響するので、行っていない。
- iPhoneのプライベートリレーやCloudflareのWARPを使う訪問者は、出口のIP（Cloudflareの範囲のこともある）で記録され、回数制限もそのIP単位になる。
- アクセスログはZENと共用の`/var/log/nginx/access.log`。訪問者のIPになるのはIROCAの行だけ。
- Cloudflareの範囲が変わったら、ファイルを更新して`nginx -t`のあとで再読み込みする。範囲の確認は監視の作業に含める（16章）。
- 戻すときは、バックアップ（`/root/iroca-nginx-202611072102.bak`）を`sites-available/iroca`に戻してファイルを削除し、`nginx -t`のあとで再読み込みする。

## 5. PHP 8.4 / PHP-FPM

`/etc/php/8.4/fpm/pool.d/ec.conf`：ユーザー`ec-deploy`、ソケット`/run/php/php8.4-fpm-ec.sock`、`pm=ondemand`、`pm.max_children=5`、`pm.max_requests=500`、`clear_env=yes`。

**占いと決済Webhookが同じプールを使う。** 占いはOpenAIを呼ぶ間ワーカーを占有する（既定の待ち時間は1回30秒で、1回の占いに2回呼ぶ）。占いが5件重なると、WebhookもWebの画面も待たされる。Webhookを取りこぼした場合は定期照合で回復する。定期照合は7章のとおり動いている。公開前に、占い専用プールの要否を負荷から判断する。

変更後は`php-fpm8.4 -tt`で確認する。ZENのプールは変更しない。

## 6. MySQL 8.0

- DB`ec`、ユーザー`ec_user`@`127.0.0.1`、権限は`ec.*`のみ。接続はTCP。
- 並行テストとCIは本番と同じ`mysql:8.0.46`で実行する（10章）。本番を更新するときは、`.github/workflows/ci.yml`と`tests/Support/Concurrency/ConcurrencyHarness.php`も合わせて変更する。
- **サーバーの設定とバージョンはZENと共有する。** `my.cnf`の変更、再起動、8.4への更新は、ZENの影響確認と承認を得てから行う。
- `log_error_verbosity`が既定の2だと、デッドロックの記録はエラーログに残らない。InnoDBの`lock_deadlocks`カウンタでは確認できる。本番の値は未確認で、サーバー全体の設定なので変更しない。
- DROP、TRUNCATE、初期化、SQLインポート、権限変更は、対象DBを確認し、明示の承認を得てから行う。

## 7. キューとスケジューラー

2026年11月7日に適用した。`/etc/systemd/system/`に次の3ファイルを置き、`iroca-queue.service`と`iroca-schedule.timer`を有効にしている。

`iroca-queue.service`

```ini
[Unit]
Description=iroca queue worker
After=mysql.service

[Service]
User=ec-deploy
Group=ec-deploy
WorkingDirectory=/var/www/ec
ExecStart=/usr/bin/php artisan queue:work database --sleep=3 --tries=3 --timeout=60 --max-time=3600 --memory=128
Restart=always
RestartSec=5
MemoryMax=192M
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
```

`iroca-schedule.service`

```ini
[Unit]
Description=iroca scheduler run
After=mysql.service

[Service]
Type=oneshot
User=ec-deploy
Group=ec-deploy
WorkingDirectory=/var/www/ec
ExecStart=/usr/bin/php artisan schedule:run
TimeoutStartSec=15min
MemoryMax=256M
NoNewPrivileges=true
```

`iroca-schedule.timer`

```ini
[Unit]
Description=Run the iroca scheduler every minute

[Timer]
OnCalendar=minutely
AccuracySec=1s
Unit=iroca-schedule.service

[Install]
WantedBy=timers.target
```

適用直後の確認結果。

| 項目 | 結果 |
|---|---|
| ワーカー | 動作中、再起動0回、メモリ約34MB、実行ユーザー`ec-deploy` |
| タイマー | 動作中。適用後の16:32の実行で定期照合が成功 |
| `laravel.log` | 変化なし |
| IROCAの`/up`、ZENの`/` | 適用前と同じ（200、302） |
| サーバー全体のメモリ | 利用可能 約2.8GB、スワップ未使用 |
| ワーカーのジョブ処理 | メールの設定後に、キューに入れたメールが届いた（9章） |

未確認の項目。

- 在庫監査（03:00）とカート掃除（03:30）の実行
- VPS再起動後の自動起動

運用上の注意。

- 実行内容は定期照合（毎分）、在庫監査（03:00）、カート掃除（03:30）。時刻は`APP_TIMEZONE`に従う。
- ワーカーは起動時のコードと設定を持ち続ける。`.env`の変更や配備のあとは`php artisan queue:restart`を実行する。
- 停止しても通知は届かない。監視の対象に加える（16章）。

元に戻すには、`systemctl disable --now iroca-queue.service iroca-schedule.timer`で止めて3ファイルを削除し、`systemctl daemon-reload`を実行する。

## 8. SSL

- Cloudflareはプロキシ有効、SSLモードはFull (strict)。
- オリジンはLet's Encrypt証明書で、Certbotが自動更新する。
- Stripeの本番Webhookを登録するときに、CloudflareのWAFやボット対策に遮断されないことを確認する。

## 9. 本番の環境変数（値は`/var/www/ec/.env`にのみ置く）

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://iroca.art-full-stack.com  # メールのリンクはこの値で作られる
APP_TIMEZONE=Asia/Tokyo
EC_ENABLED=false
DB_DATABASE=ec
DB_USERNAME=ec_user
SESSION_COOKIE=iroca-session
SESSION_DOMAIN=null                       # 親ドメインへCookieを渡さない
```

ほかに`APP_KEY`、`DB_PASSWORD`、`EC_STRIPE_SECRET`、`EC_STRIPE_WEBHOOK_SECRET`、`OPENAI_API_KEY`、`GOOGLE_*`、`MAIL_*`、`CONTACT_EMAIL`が必要。

### メール送信はGmail SMTP（2026年11月7日に決定・適用）

ZEN専用のGmailアカウントのSMTPで送る。将来ZENとECを統合する予定であること、送信上限が1日500通で、Resendの無料プラン（1日100通）より多いことが理由。

```
MAIL_MAILER=smtp
MAIL_SCHEME=null                          # 587番はSTARTTLS、465番はSSLになる
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=artfullstack@gmail.com
MAIL_PASSWORD=                            # IROCA用に発行したアプリパスワード
MAIL_FROM_ADDRESS=artfullstack@gmail.com  # 認証したアカウントと同じにする
MAIL_FROM_NAME="IROCA"
MAIL_TIMEOUT=10                           # SMTPの応答を待つ秒数
CONTACT_EMAIL=artfullstack@gmail.com      # 問い合わせと管理者通知の宛先
```

- アプリパスワードはZENと別にIROCA用を発行する。片方だけ無効にできる。アプリパスワードではGmail全体を扱えるので、`.env`の外に出さない（13章）。
- 送信上限はZENと共有する。超えると、両方のメールが止まる。
- 送信元はGmailのアドレスになる。Gmailは認証したアカウント以外のFromを書き換える。
- 問い合わせは送信元と宛先が同じアドレスになり、Reply-Toに訪問者のアドレスが入る。
- 適用時の疎通確認で、直接送信とワーカー経由の送信の2通が届き、問い合わせの返信先も正しかった。それまでは`log`で送信されず、利用者は0人だった。
- 会員登録、確認メールの再送、パスワード再設定（本人、管理者）、問い合わせは、リクエストの中で送る。送信に失敗したら例外をログに残し、画面にエラーを出す。500エラーにはしない。会員登録は完了し、確認メールは再送ボタンで送り直せる。問い合わせは入力内容を残す。
- パスワード再設定は送信に失敗したときだけエラーを出すので、その間は登録の有無が分かる。
- 注文メールと管理者通知はキューで送り、30秒後と120秒後に再試行する（計3回）。
- 独自ドメインの送信サービスへ移るときは、`MAIL_*`の変更で済む。

### `APP_TIMEZONE`は`Asia/Tokyo`（2026年11月7日に決定）

- 無料回数と日次占いは日本時間の0時に切り替わる。定期実行の時刻（7章）も日本時間で動く。
- 実データが入ったあとで変更すると、保存済みの時刻が9時間ずれて読まれる。
- Eloquentは日時の値を変換せずに保存するので、UTCのCarbonをそのまま代入すると9時間ずれる。Unix時刻は`now()->setTimestamp(...)`のように、アプリのタイムゾーンで受ける。

## 10. CI/CD（GitHub Actions）

### CI：PRごとに自動実行（`.github/workflows/ci.yml`）

1. PHP 8.4をセットアップし、`composer install`、`vendor/bin/pint --test`
2. Node 22で`npm ci && npm run build`（機能テストがViteのマニフェストを使うので、テストより先に行う）
3. 使い捨ての`APP_KEY`を生成し、`vendor/bin/pest`（SQLite）
4. 本番と同じ`mysql:8.0.46`を`docker run`で`127.0.0.1:33307`に起動し、`vendor/bin/pest -c phpunit.mysql.xml`
5. InnoDBの`lock_deadlocks`カウンタが0であることを確認する（アプリが再試行するので、テストが成功してもデッドロックが起きている場合がある）

Actionはコミットのハッシュで固定する。

### 配備

配備スクリプトはない（2026年11月7日に確認。`/usr/local/sbin/deploy-ec`は存在しなかった）。自動配備のワークフローもリポジトリにない。`/var/www/ec`は`main`を追うGitの作業ツリーで、リポジトリは公開なので認証なしで取得できる。

2026年11月7日に`b702ec4`から`c83a327`へ手作業で配備した。migration、`composer.lock`、`package-lock.json`、CSS、JSに差分がなかったので、取得と次の3つで済んだ。

```bash
cd /var/www/ec
sudo -u ec-deploy git fetch origin
sudo -u ec-deploy git status --porcelain            # 何も出ないこと
sudo -u ec-deploy git merge --ff-only origin/main
sudo -u ec-deploy php artisan optimize
sudo -u ec-deploy php artisan queue:restart
```

確認：IROCAの`/up`は200、ZENの`/`は302。`mail.mailers.smtp.timeout`は10、`app.timezone`は`Asia/Tokyo`、未実行のmigrationは0。ワーカーは配備の時刻に起動し直した。

- PHP-FPMのopcacheは`validate_timestamps`が既定（有効、2秒）なので、FPMの再読み込みは不要。ZENにも影響しない。
- migration、依存パッケージ、画面のビルドを含む配備では、次も必要になる。配備前のバックアップ、`composer install --no-dev`、`npm ci && npm run build`、`php artisan migrate --force`。これらを含む手順書かスクリプトを用意する（16章）。

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
| 配備後に不具合 | migrationを含まない配備なら、`git reset --keep <前のコミット>`、`php artisan optimize`、`php artisan queue:restart`。migrationを含む場合は下の行 |
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
| Gmailのアプリパスワード | `.env`のみ | IROCA専用に発行する。Gmail全体を扱えるので、漏えい時はすぐに取り消す |
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
| メール送信 | **共有**。同じGmailアカウントで、送信上限も共有する。アプリパスワードは別 |
| Cookie | ホスト限定（`SESSION_DOMAIN`なし）。親ドメインへ送らない |
| バックアップ | IROCAは専用のR2バケット。ZENの扱いは本書の対象外 |

共有部分は片方の障害や高負荷がもう片方に及ぶ。共有部分の変更は、IROCA専用の設定で実現できないかを先に検討する。DNSの変更は`iroca`のサブドメインに限り、親ドメインのSPF等を変更しない。

## 16. 公開までに残る作業

優先順に並べる。

メール送信、ワーカーの処理、接続元IPの復元、最新コード（`c83a327`）の配備は確認済み（4、7、9、10章）。

1. テストモードのStripeで通しテスト（Webhook、照合、返金、メール）
2. `.env`の保管と復旧手順、バックアップのcron実行の確認、復元の練習（11、12章）
3. migrationやビルドを含む配備の手順書かスクリプト（10、12章）
4. ワーカーとタイマーの監視、再起動後の自動起動の確認、Cloudflareの範囲の確認（4、7章）
5. 負荷の実測、占い専用プールの判断、443番の既定サーバーの判断（4、5章）
6. 法務・販売条件が揃ったら本番Stripeへ切り替え、`EC_ENABLED=true`
