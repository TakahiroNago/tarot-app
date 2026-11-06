# EC専用VPS 構築設計（iroca）

**本書は設計であり、まだどのサーバーにも適用しておらず、設定例も実機で検証していない。** 数値は見積もりで、構築後に実測して調整する。VPSの契約・構築後に、改めて指示を受けてから作業する。

## 1. 前提

| 項目 | 内容 |
|---|---|
| サーバー | 新規のConoHa VPS。Ubuntu 24.04、メモリ2GB。ZENとは別サーバー |
| 公開URL | `https://iroca.art-full-stack.com` |
| 配置先 | `/var/www/ec`（VPS上に`.env`はまだない） |
| アプリ | このリポジトリ一式。タロット機能も公開し、ECは`EC_ENABLED`で別に制御する |
| ミドルウェア | Nginx、PHP 8.4（FPM）、MySQL 8.4 LTS |
| 時刻 | OSと`APP_TIMEZONE`はUTC。注文画面の表示と税率の判定だけ日本時間 |

### 契約前・構築時に確認すること（未確認）

- ConoHaの2GBプランの有無、CPU、ディスク、料金、Ubuntu 24.04の提供、自動バックアップの条件を公式ページで確認する。
- MySQL APTリポジトリが8.4をUbuntu 24.04向けに提供していることを、リポジトリの一覧で確認する。Ubuntu標準のパッケージは8.4ではない。
- PHP 8.4はUbuntu 24.04の標準ではないため、`ppa:ondrej/php`を使う案とする。
- `art-full-stack.com`は登録済みでDNSはCloudflareにある。この管理権限を持つことを確認する。

## 2. 全体構成

```
利用者 ─HTTPS→ Nginx ─┬→ PHP-FPM iroca-web（画面・Webhook）
Stripe ─Webhook→      └→ PHP-FPM iroca-ai （/tarot/draw・/tarot/daily）
                                │
systemd queue worker ───────────┼→ MySQL 8.4（127.0.0.1のみ）
systemd timer（毎分）────────────┘
systemd timer（毎日・毎時）→ 暗号化バックアップ → VPS外の保管先

GitHub Actions ─ビルド済み成果物をrsync→ /var/www/ec/releases/<sha>
```

- **ビルドはVPSで行わない。** ComposerとNode.jsはGitHub Actions側で使う。2GBのメモリをビルドに使わないためで、VPSに両者は不要。
- **PHP-FPMのプールを2つに分ける。** 占いはOpenAIを呼ぶ間ワーカーを占有する（既定の待ち時間は1回30秒で、1回の占いに2回呼ぶ）。分けることで、占いが混んでも決済のWebhookや購入画面が止まらない。

### メモリ配分（2GB、見積もり）

| 項目 | 設定 | 見積もり |
|---|---|---|
| OS、sshd、journald等 | | 250MB |
| Nginx | worker 2 | 30MB |
| MySQL | buffer pool 384MB | 600MB |
| PHP-FPM iroca-web | 最大6 × 50MB | 300MB |
| PHP-FPM iroca-ai | 最大3 × 50MB | 150MB |
| OPcache | 共有 | 128MB |
| キューワーカー | 1プロセス | 70MB |
| スケジューラー | 毎分・短時間 | 50MB |
| **合計** | | **約1.6GB** |
| 残り | ページキャッシュ、バックアップ処理 | 約400MB |

スワップ2GB（`vm.swappiness=10`）は保険とする。常時スワップするならプラン変更を検討する。構築後に`free -m`と負荷試験で確認する。

## 3. OSの初期設定

- ユーザーを3つに分ける。
  - `deploy`：SSH配備専用。`.env`を読めない。
  - `iroca-app`：FPMとワーカーの実行ユーザー。ログイン不可。
  - 管理者：作業用の個人アカウント。
- SSHは公開鍵のみ。`PermitRootLogin no`、`PasswordAuthentication no`。ConoHaのセキュリティグループ（利用できる場合）とUFWで22、80、443のみ許可する。22は可能なら接続元を限定する。
- `fail2ban`、`unattended-upgrades`（セキュリティ更新のみ）を有効にする。
- ディレクトリ

```
/var/www/ec/
  releases/<sha>/      deploy所有、iroca-appは読み取りのみ
  current -> releases/<sha>
  shared/.env          iroca-app所有、mode 640
  shared/storage/      iroca-app所有（ログ、セッション、画像）
```

## 4. Nginx

`/etc/nginx/sites-available/iroca`（主要部分）

```nginx
limit_req_zone $binary_remote_addr zone=iroca_ai:5m rate=10r/m;

# 署名付きURLやsession_idを残さないよう、クエリ文字列を記録しない
log_format iroca '$remote_addr [$time_local] "$request_method $uri" $status $body_bytes_sent $request_time';

# 未知のHostとIP直指定を拒否する。TLSの段階で拒否しても、正規のSNIのあとに
# 偽のHostを送る要求は届くので、return 444も必要
server {
    listen 80 default_server;
    server_name _;
    access_log off;
    return 444;
}

server {
    listen 443 ssl http2 default_server;
    server_name _;
    ssl_reject_handshake on;
    access_log off;
    return 444;
}

server {
    listen 80;
    server_name iroca.art-full-stack.com;
    location /.well-known/acme-challenge/ { root /var/www/letsencrypt; }
    location / { return 301 https://$host$request_uri; }
}

server {
    listen 443 ssl http2;
    server_name iroca.art-full-stack.com;
    root /var/www/ec/current/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/iroca.art-full-stack.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/iroca.art-full-stack.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    # includeSubDomainsは付けない（親ドメインの他のサービスへ影響させないため）
    add_header Strict-Transport-Security "max-age=31536000" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    access_log /var/log/nginx/iroca.access.log iroca;
    error_log  /var/log/nginx/iroca.error.log warn;

    # 商品画像 5MB × 8枚
    client_max_body_size 45M;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ /\.(?!well-known) { deny all; }
    location ^~ /storage/ { try_files $uri =404; location ~ \.php$ { deny all; } }
    location ^~ /build/ { expires 1y; add_header Cache-Control "public, immutable"; try_files $uri =404; }

    location = /tarot/draw  { limit_req zone=iroca_ai burst=5 nodelay; try_files /nonexistent @ai; }
    location = /tarot/daily { limit_req zone=iroca_ai burst=5 nodelay; try_files /nonexistent @ai; }

    location @ai {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_pass unix:/run/php/iroca-ai.sock;
        fastcgi_read_timeout 90s;
    }

    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/iroca-web.sock;
        fastcgi_read_timeout 60s;
    }
    location ~ \.php$ { return 404; }
}
```

`nginx.conf`は`worker_processes 2`、`server_tokens off`。`$realpath_root`により、リリース切り替え時に古いパスを参照しない。

### Hostヘッダーの防御

パスワード再設定と会員登録確認のメールは、Webリクエストの中でリンクを作る。偽のHostを受け入れると、正規のメールのリンク先が攻撃者のサイトになり、再設定トークンを盗まれる。次の3層で防ぐ。

| 層 | 内容 |
|---|---|
| Nginx | 上の`default_server`が未知のHostとIP直指定を拒否する |
| アプリ | `bootstrap/app.php`の`trustHosts`が`iroca.art-full-stack.com`のみ許可（サブドメインも不可） |
| リンク生成 | 本番環境では`APP_URL`をルートURLに固定し、`https`を強制する |

- Ubuntu標準の`sites-enabled/default`は同じポートの`default_server`を持つので、無効にする。
- **`APP_ENV=local`では`trustHosts`が無効になる。** 本番は必ず`production`にする。
- 死活監視や配備の確認はドメイン名で行う。IP直指定は拒否される。
- このVPSにはEC以外のサイトを置かない前提。他のサイトを同居させる場合は、この設定を見直す。

配備後の確認（データは変わらない）。

```bash
# 80番：何も返らず切断される（curlの終了コード52）
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: evil.example' http://iroca.art-full-stack.com/
# 443番、正規のSNIに偽のHost：何も返らず切断される（HTTP/2では終了コード56、HTTP/1.1では52）
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: evil.example' https://iroca.art-full-stack.com/login
# 443番、偽のSNI：TLSの段階で拒否される（終了コード35）
curl -s -o /dev/null --connect-to evil.example:443:iroca.art-full-stack.com:443 https://evil.example/
# 正規：200
curl -s -o /dev/null -w '%{http_code}\n' https://iroca.art-full-stack.com/login
```

## 5. PHP 8.4 / PHP-FPM

拡張：`ctype curl dom fileinfo filter hash iconv json libxml mbstring openssl pcre pdo pdo_mysql session tokenizer xml`、`opcache`。パッケージは`php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl`。

`/etc/php/8.4/fpm/pool.d/iroca-web.conf`

```ini
[iroca-web]
user = iroca-app
group = iroca-app
listen = /run/php/iroca-web.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 6
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
pm.max_requests = 500
request_terminate_timeout = 60s
php_admin_value[memory_limit] = 128M
```

`iroca-ai.conf`は同じ内容で、`listen = /run/php/iroca-ai.sock`、`pm = ondemand`、`pm.max_children = 3`、`pm.process_idle_timeout = 30s`、`request_terminate_timeout = 90s`。既定の`www.conf`は削除する。

`/etc/php/8.4/fpm/conf.d/90-iroca.ini`

```ini
upload_max_filesize = 6M
post_max_size = 48M
max_file_uploads = 10
expose_php = Off
opcache.enable = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 0
```

`validate_timestamps=0`のため、配備時にFPMの再読み込みが必須。

## 6. MySQL 8.4

`/etc/mysql/mysql.conf.d/zz-iroca.cnf`

```ini
[mysqld]
bind-address = 127.0.0.1
mysqlx = OFF
skip_name_resolve = ON
performance_schema = OFF
innodb_buffer_pool_size = 384M
innodb_redo_log_capacity = 128M
innodb_print_all_deadlocks = ON
max_connections = 30
table_open_cache = 400
tmp_table_size = 16M
max_heap_table_size = 16M
binlog_expire_logs_seconds = 259200
character_set_server = utf8mb4
collation_server = utf8mb4_0900_ai_ci
```

- `max_connections`はFPM 9、ワーカー1、スケジューラー、バックアップ、管理の合計に余裕を持たせた値。
- DBユーザーは用途別に分ける。

| ユーザー | 権限 | 用途 |
|---|---|---|
| `iroca_app` | `iroca`のすべて（migrateに必要） | アプリ |
| `iroca_backup` | `iroca`への`SELECT, LOCK TABLES, SHOW VIEW, TRIGGER, EVENT` | バックアップ |

- 接続はUnixソケットを基本にする（`DB_SOCKET=/var/run/mysqld/mysqld.sock`）。並行テストはTCP接続でのみ確認済みなので、構築時に接続を確認する。

## 7. キューとスケジューラー

`/etc/systemd/system/iroca-queue.service`

```ini
[Unit]
Description=iroca queue worker
After=mysql.service

[Service]
User=iroca-app
WorkingDirectory=/var/www/ec/current
ExecStart=/usr/bin/php artisan queue:work database --sleep=3 --tries=3 --timeout=60 --max-time=3600 --memory=128
Restart=always
RestartSec=5
MemoryMax=192M

[Install]
WantedBy=multi-user.target
```

`iroca-schedule.service`（`Type=oneshot`、`ExecStart=/usr/bin/php artisan schedule:run`、`User=iroca-app`）と`iroca-schedule.timer`（`OnCalendar=minutely`、`AccuracySec=1s`）。

スケジューラーの実行内容：定期照合（毎分）、在庫監査、カート掃除（UTC 03:00・03:30）。**停止するとWebhookを取りこぼした注文が`pending_payment`のまま残る**ので、監視対象にする。

## 8. SSL

- **Cloudflareは「DNSのみ」（灰色の雲）にする。** プロキシを有効にすると、実際の接続元IPの取得と信頼プロキシの設定が必要になり、レート制限にも影響する。必要になった時点で別途設計する。
- Let's Encryptを`certbot certonly --webroot -w /var/www/letsencrypt -d iroca.art-full-stack.com`で取得する。DNSレコードを追加してから実行する。
- 更新はcertbotのsystemd timer。`--deploy-hook "systemctl reload nginx"`。

## 9. 本番の環境変数（値は`shared/.env`にのみ置く）

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://iroca.art-full-stack.com  # メールのリンクはこの値で作られる
APP_TIMEZONE=UTC
APP_DISPLAY_TIMEZONE=Asia/Tokyo
EC_ENABLED=false
DB_CONNECTION=mysql
DB_DATABASE=iroca
DB_USERNAME=iroca_app
DB_SOCKET=/var/run/mysqld/mysqld.sock
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_COOKIE=iroca_session
CACHE_STORE=database
QUEUE_CONNECTION=database
LOG_CHANNEL=daily
LOG_DAILY_DAYS=14
FILESYSTEM_DISK=local
MAIL_MAILER=（送信サービス）
```

他に`APP_KEY`、`DB_PASSWORD`、`EC_STRIPE_SECRET`、`EC_STRIPE_WEBHOOK_SECRET`、`OPENAI_API_KEY`、`GOOGLE_*`、`MAIL_*`、`CONTACT_EMAIL`が必要。`SESSION_DOMAIN`は設定しない（親ドメインへCookieを渡さないため）。

## 10. CI/CD（GitHub Actions）

### CI：PRごとに自動実行（`.github/workflows/ci.yml`）

1. PHP 8.4をセットアップし、`composer install`、`vendor/bin/pint --test`
2. `vendor/bin/pest`（SQLite）
3. `mysql:8.4`を`docker run`で`127.0.0.1:33307`に起動し、`vendor/bin/pest -c phpunit.mysql.xml`
4. Node 22で`npm ci && npm run build`

Actionはコミットのハッシュで固定する。

### CD：手動実行のみ（`.github/workflows/deploy.yml`）

- トリガーは`workflow_dispatch`で、`main`のみ許可する。
- `production`環境のシークレットに`DEPLOY_SSH_KEY`と`DEPLOY_KNOWN_HOSTS`を置く。**アプリの秘密情報はGitHubに置かない。** 承認者の必須化はGitHubのプランによって使えない場合があるので、確認する。

```
1. composer install --no-dev --optimize-autoloader
2. npm ci && npm run build（public/buildを同梱、node_modulesは除外）
3. rsync → /var/www/ec/releases/<sha>/
4. ssh deploy@... sudo /usr/local/bin/iroca-activate <sha>
```

### 切り替えスクリプト（`/usr/local/bin/iroca-activate`、root所有）

`deploy`のsudo権限はこのスクリプトと`iroca-rollback`のみに限定する。

```
1. <sha>の形式を検証し、リリースの存在を確認
2. .env と storage を shared へリンク、public/storage を shared へリンク
3. バックアップを取得（失敗したら中止）
4. iroca-app で php artisan migrate --force
5. iroca-app で config:cache、route:cache、view:cache
6. current を原子的に切り替え
7. php-fpm 再読み込み、php artisan queue:restart
8. https://iroca.../up を確認、失敗なら自動で前のリリースへ戻す
9. 古いリリースを削除（5世代保持）
```

migrationは旧コードでも動く形（追加中心）にする。

## 11. 自動バックアップ

| 対象 | 頻度 | 方法 |
|---|---|---|
| データベース | 毎日、配備前 | `mysqldump --single-transaction --quick --routines --no-tablespaces` |
| バイナリログ | 毎時 | VPS外へ転送（直近の注文を復元するため） |
| 商品画像 | 毎日 | `shared/storage/app/public` をtar |
| `.env`、`age`秘密鍵 | 変更時 | パスワードマネージャーへ手動保管。VPS外 |

- `age`の**公開鍵**で暗号化してから、`rclone`でVPS外のオブジェクトストレージへ送る。秘密鍵はVPSに置かない。
- 保持：ローカル7日、VPS外は日次30世代・月次12世代（案。保存期間の方針決定後に確定）。個人情報を含むので、保存期間の方針に従う。
- 失敗時にメールまたは外部の死活監視で通知する。
- ConoHaの自動バックアップは追加の保険とし、条件を確認して有効にする。VM全体の復旧には便利だが、これだけに頼らない。
- 目標（案）：RPO 1時間、RTO 半日。

## 12. 復元・ロールバック手順

### 状況別の対応

| 状況 | 対応 |
|---|---|
| 購入を今すぐ止めたい | `.env`を`EC_ENABLED=false`にして`php artisan config:cache`、FPM再読み込み |
| 配備後に不具合 | `sudo iroca-rollback`（`current`を前のリリースへ戻し、FPM再読み込み、`queue:restart`） |
| migrationに問題 | 修正版を前進で配備する。`migrate:rollback`は検証済みの場合のみ |
| データ破損 | 下記のDB復元 |
| VPS全損 | 新規VPSに本設計で構築し、下記のDB復元 |

### DB復元

1. `EC_ENABLED=false`にし、キューとスケジューラーを停止する（`systemctl stop iroca-queue iroca-schedule.timer`）。
2. 現状のダンプを別に保存する。
3. 最新のダンプを`age -d`で復号し、別名のDB（`iroca_restore`）へ入れて内容を確認する。
4. バイナリログを`mysqlbinlog --stop-datetime=...`で障害の直前まで適用する。
5. 問題なければ本番DBへ切り替える。
6. **Stripeと照合する。** 復元地点より後の決済はDBにない。Dashboardの支払いと注文を突き合わせ、不足分を確認する。
7. キューとスケジューラーを再開する。Webhookの再送はDashboardから行う。
8. 確認後に`EC_ENABLED=true`へ戻す。

### 公開前の練習（必須）

別のDBへ復元して件数を確認する。`iroca-rollback`を実行して戻す。Webhookの再送を試す。

## 13. 秘密情報の管理

| 秘密情報 | 置き場所 | 管理 |
|---|---|---|
| SSH（管理者） | 各自のPC、パスフレーズ付き`ed25519` | ZENと**別の鍵**を使う |
| SSH（配備） | GitHubの環境シークレット | 配備専用の鍵。`deploy`ユーザーのみ。known_hostsで固定 |
| DBパスワード | `shared/.env`（640）、パスワードマネージャー | 用途別に生成。外部へ公開しない |
| Stripe本番キー | `shared/.env`のみ | 制限付きキー（`rk_live_`）を推奨。Checkoutと返金に必要な権限に絞り、構築時に動作確認する。リポジトリ、GitHub、チャットに置かない |
| Webhookシークレット | `shared/.env`のみ | Dashboardで再発行可能 |
| `APP_KEY` | `shared/.env`、パスワードマネージャー | **再生成しない。** ゲスト注文リンクと決済の再試行データが無効になる |
| バックアップ鍵 | 秘密鍵はVPS外のみ | 紛失するとバックアップを復号できない |

- `bootstrap/cache/config.php`にも秘密情報が入るので、`iroca-app`のみ読める権限にする。
- 漏えい時はキーを再発行し、`.env`を更新して`config:cache`を実行する。手順を別途用意する。

## 14. EC停止中の動作（確認済み）

テストと実サーバーでの確認結果。

| 項目 | 動作 |
|---|---|
| 商品・カート・注文手続き | 404 |
| 注文データ、ゲストリンクの署名 | 変更なし。再開すれば同じリンクで開ける |
| ゲストリンクの有効期限（30日） | 停止中も進む |
| 支払い完了後の戻り先、注文履歴、ゲストリンク | **404** |
| 停止前に開いた決済画面 | Stripe上で支払える。Webhookで`paid`になり、在庫も確定する |
| 確認メール | 送信される。ただしメール内のリンクは停止中404 |
| お支払い待ちの注文 | 購入者は再開も取り消しもできない。期限切れで解放される |
| Webhook、定期照合 | 動作する |
| 管理画面（発送、返金） | 動作する |
| 商品画像 | ファイル名を知っていれば**取得できる**。推測した名前は404、一覧は不可 |

## 15. ZENからの独立

| 項目 | 状態 |
|---|---|
| サーバー、OS、Nginx、PHP、MySQL | 別のVPSで完全に分離 |
| データベース、ユーザー | 別 |
| SSH鍵、配備用ユーザー | 別の鍵を発行 |
| Stripe | EC専用アカウント |
| Cookie | ホスト限定（`SESSION_DOMAIN`なし）。親ドメインへ送らない |
| HSTS | `includeSubDomains`なし |
| ネットワーク | 相互接続なし。バックアップ先も共用しない |

**共有される可能性があるのは`art-full-stack.com`のDNS（Cloudflare）だけ。** ZENが同じドメインを使っている場合、変更はサブドメインのAレコード追加に限る。メール送信の認証レコードは`iroca`のサブドメインに設定し、親ドメインのSPF等を変更しない。

## 16. 構築の順番

1. ConoHaの条件確認、VPS契約
2. OS初期設定（3章）
3. Nginx、PHP、MySQL、systemd（4〜7章）
4. DNS追加、SSL（8章）
5. バックアップ（11章）
6. CI/CD（10章）、`EC_ENABLED=false`で初回配備
7. テストモードのStripeで通しテスト、復元の練習
8. 負荷の実測と設定調整
9. 法務・販売条件が揃ったら本番Stripeへ切り替え、`EC_ENABLED=true`
