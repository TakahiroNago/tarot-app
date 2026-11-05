# Tarot App

This directory is a standalone copy of the legacy tarot application, restored
from commit `ac4d00e`. It has its own Laravel bootstrap, routes, migrations,
frontend, and dependency manifests.

## Local setup

Run these commands from `tarot-app/`:

```powershell
composer install
npm ci
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
php artisan key:generate
php artisan migrate
npm run build
php artisan test
php artisan serve --port=8001
```

The local URL defaults to `http://localhost:8001`. The SQLite database defaults
to this application's own `database/database.sqlite`; its session cookie also
has a Tarot-specific name. Keep `.env`, generated keys, databases, and service
credentials out of version control. For deployment, configure a dedicated
database, session/cache/queue resources, storage, domain, and provider
credentials for Tarot.

## Integration boundary

Keep Tarot's routes, migrations, and app-specific configuration inside this
directory while it is developed independently. If accounts or infrastructure
are shared in the future, introduce that as an explicit integration.
