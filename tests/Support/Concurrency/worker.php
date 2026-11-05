<?php

/**
 * A concurrency worker process, started only by ConcurrencyHarness.
 *
 * Usage: php worker.php <worker-id> <run-id> <barrier-name> <action> <base64-json-payload>
 *
 * The target is verified from the process environment before the framework boots, and verified
 * again from the resolved configuration before the first connection. The final stdout line is JSON.
 */

use App\Services\StripeBillingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\ConcurrencyActions;
use Tests\Support\Concurrency\ConcurrencyDatabaseGuard;
use Tests\Support\Concurrency\ConcurrencyHarness;
use Tests\Support\Stripe\FakeStripeBillingService;

const WORKER_EXIT_UNSAFE_TARGET = 3;
const WORKER_EXIT_BARRIER_TIMEOUT = 4;

require __DIR__.'/../../../vendor/autoload.php';

[, $workerId, $runId, $barrierName, $action, $encodedPayload] = $argv;

$unsafeTargetExit = function (RuntimeException $exception): never {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);

    exit(WORKER_EXIT_UNSAFE_TARGET);
};

try {
    ConcurrencyDatabaseGuard::assertSafeTarget([
        'driver' => getenv('DB_CONNECTION') ?: null,
        'host' => getenv('DB_HOST') ?: null,
        'port' => getenv('DB_PORT') ?: null,
        'database' => getenv('DB_DATABASE') ?: null,
        'url' => getenv('DB_URL') ?: null,
        'unix_socket' => getenv('DB_SOCKET') ?: null,
    ]);
} catch (RuntimeException $exception) {
    $unsafeTargetExit($exception);
}

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->useEnvironmentPath(__DIR__);
$app->make(Kernel::class)->bootstrap();

try {
    ConcurrencyDatabaseGuard::assertSafeTarget([
        ...config('database.connections.mysql'),
        'driver' => config('database.default') === 'mysql' ? 'mysql' : config('database.default'),
    ]);
} catch (RuntimeException $exception) {
    $unsafeTargetExit($exception);
}

FakeStripeBillingService::install($app);

$payload = json_decode(base64_decode($encodedPayload, true), true, 512, JSON_THROW_ON_ERROR);

DB::connection()->getPdo();
DB::table(ConcurrencyHarness::READY_TABLE)->insert(['run_id' => $runId, 'worker_id' => (int) $workerId]);

$barrierOpened = DB::selectOne('SELECT GET_LOCK(?, 60) AS acquired', [$barrierName])->acquired === 1;
if (! $barrierOpened) {
    fwrite(STDERR, 'Timed out waiting for the start barrier.'.PHP_EOL);

    exit(WORKER_EXIT_BARRIER_TIMEOUT);
}
DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$barrierName]);

$startedAt = microtime(true);
$result = ConcurrencyActions::run($action, $payload);

fwrite(STDOUT, PHP_EOL.json_encode([
    ...$result,
    'worker_id' => (int) $workerId,
    'started_at' => $startedAt,
    'finished_at' => microtime(true),
    'env_file_loaded' => env('CONCURRENCY_ENV_FILE_SENTINEL') !== null,
    'stripe_service' => $app->make(StripeBillingService::class)::class,
], JSON_THROW_ON_ERROR).PHP_EOL);
