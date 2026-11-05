<?php

namespace Tests\Support\Concurrency;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Starts real PHP processes against the disposable MySQL database and releases them together.
 *
 * Start the database (its credentials are local to the container and are not secrets):
 *
 *   docker run -d --rm --name tarot-ec-concurrency-test \
 *     -e MYSQL_ROOT_PASSWORD=concurrency-root -e MYSQL_DATABASE=tarot_ec_concurrency_test \
 *     -e MYSQL_USER=concurrency -e MYSQL_PASSWORD=concurrency \
 *     -p 127.0.0.1:33307:3306 --tmpfs /var/lib/mysql \
 *     mysql:8.4 --innodb-print-all-deadlocks=ON
 *
 * Run the suite with `vendor/bin/pest -c phpunit.mysql.xml`, then stop the container with
 * `docker stop tarot-ec-concurrency-test`.
 */
final class ConcurrencyHarness
{
    public const READY_TABLE = 'concurrency_harness_ready';

    private const WORKER_TIMEOUT_SECONDS = 120;

    private const READY_TIMEOUT_SECONDS = 60;

    public static function assertCurrentTargetIsSafe(): void
    {
        ConcurrencyDatabaseGuard::assertSafeTarget(self::currentTarget());
    }

    /**
     * Rebuild the schema, committing real rows that other processes can see.
     */
    public static function rebuildSchema(): void
    {
        self::assertCurrentTargetIsSafe();

        ConcurrencyDatabaseGuard::assertSchemaMayBeRebuilt(
            (string) DB::selectOne('SELECT DATABASE() AS name')->name,
            array_map(
                fn (array $table): string => $table['name'],
                Schema::getTables(ConcurrencyDatabaseGuard::REQUIRED_DATABASE),
            ),
        );

        Artisan::call('migrate:fresh', ['--force' => true]);

        Schema::create(ConcurrencyDatabaseGuard::MARKER_TABLE, function (Blueprint $table): void {
            $table->id();
        });
        Schema::create(self::READY_TABLE, function (Blueprint $table): void {
            $table->string('run_id', 36);
            $table->unsignedInteger('worker_id');
            $table->primary(['run_id', 'worker_id']);
        });
        Schema::create(SharedDatabaseStripeSessions::TABLE, function (Blueprint $table): void {
            $table->string('idempotency_key', 100)->primary();
            $table->string('session_id', 64)->unique();
            $table->char('params_hash', 64);
            $table->text('params');
            $table->string('status', 20);
            $table->string('payment_status', 20);
            $table->unsignedBigInteger('expires_at');
        });
    }

    /**
     * Run one worker per payload and return each decoded result, ordered by worker id.
     *
     * @param  array<int, array<string, mixed>>  $payloads
     * @return array<int, array<string, mixed>>
     */
    public static function run(string $action, array $payloads): array
    {
        self::assertCurrentTargetIsSafe();

        $runId = (string) Str::uuid();
        $barrierName = 'concurrency-barrier-'.$runId;
        if (DB::selectOne('SELECT GET_LOCK(?, 5) AS acquired', [$barrierName])->acquired !== 1) {
            throw new RuntimeException('Could not take the start barrier.');
        }

        $processes = [];
        try {
            foreach ($payloads as $workerId => $payload) {
                $process = self::workerProcess($workerId, $runId, $barrierName, $action, $payload);
                $process->start();
                $processes[$workerId] = $process;
            }

            self::waitUntilWorkersAreReady($runId, $processes);
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$barrierName]);
        }

        $results = [];
        foreach ($processes as $workerId => $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException(sprintf(
                    'Worker %d exited with %d: %s',
                    $workerId,
                    $process->getExitCode(),
                    trim($process->getErrorOutput()) ?: trim($process->getOutput()),
                ));
            }

            $lines = preg_split('/\R/', trim($process->getOutput()));
            $results[$workerId] = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    /**
     * Start one worker to prove its own guard stops it.
     *
     * The credentials are invalid everywhere, so a regression in the worker's guard fails at
     * authentication instead of reaching the database named by the environment.
     *
     * @param  array<string, string>  $environment
     */
    public static function runWorkerExpectedToRefuseTarget(array $environment): Process
    {
        $process = new Process(
            [PHP_BINARY, __DIR__.'/worker.php', '0', 'guard-test', 'guard-test', 'increment-on-hand', 'e30='],
            base_path(),
            [
                ...$environment,
                'DB_USERNAME' => 'concurrency_guard_test_no_access',
                'DB_PASSWORD' => 'concurrency_guard_test_no_access',
            ],
        );
        $process->setTimeout(self::WORKER_TIMEOUT_SECONDS);
        $process->run();

        return $process;
    }

    /**
     * @return array<int, string>
     */
    public static function inventoryInvariantViolations(): array
    {
        $violations = [];

        foreach (DB::table('ec_inventory_balances')->get() as $balance) {
            if ($balance->reserved < 0 || $balance->reserved > $balance->on_hand) {
                $violations[] = "variant {$balance->variant_id}: on_hand={$balance->on_hand} reserved={$balance->reserved}";
            }

            $ledger = DB::table('ec_inventory_movements')
                ->where('variant_id', $balance->variant_id)
                ->selectRaw('COALESCE(SUM(on_hand_delta), 0) AS on_hand, COALESCE(SUM(reserved_delta), 0) AS reserved')
                ->first();
            if ((int) $ledger->on_hand !== (int) $balance->on_hand || (int) $ledger->reserved !== (int) $balance->reserved) {
                $violations[] = "variant {$balance->variant_id}: the ledger does not match the balance";
            }
        }

        return $violations;
    }

    /**
     * @return array<string, mixed>
     */
    private static function currentTarget(): array
    {
        return [
            ...config('database.connections.mysql'),
            'driver' => config('database.default') === 'mysql' ? 'mysql' : config('database.default'),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function workerProcess(int $workerId, string $runId, string $barrierName, string $action, array $payload): Process
    {
        $target = self::currentTarget();
        $process = new Process(
            [
                PHP_BINARY,
                __DIR__.'/worker.php',
                (string) $workerId,
                $runId,
                $barrierName,
                $action,
                base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            ],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'APP_KEY' => (string) config('app.key'),
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => (string) $target['host'],
                'DB_PORT' => (string) $target['port'],
                'DB_DATABASE' => (string) $target['database'],
                'DB_USERNAME' => (string) $target['username'],
                'DB_PASSWORD' => (string) $target['password'],
                'DB_URL' => '',
                'DB_SOCKET' => '',
                'STRIPE_SECRET' => '',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'MAIL_MAILER' => 'array',
                'LOG_CHANNEL' => 'null',
            ],
        );
        $process->setTimeout(self::WORKER_TIMEOUT_SECONDS);

        return $process;
    }

    /**
     * @param  array<int, Process>  $processes
     */
    private static function waitUntilWorkersAreReady(string $runId, array $processes): void
    {
        $deadline = microtime(true) + self::READY_TIMEOUT_SECONDS;

        while (DB::table(self::READY_TABLE)->where('run_id', $runId)->count() < count($processes)) {
            foreach ($processes as $workerId => $process) {
                if (! $process->isRunning() && ! $process->isSuccessful()) {
                    throw new RuntimeException(sprintf(
                        'Worker %d failed before the barrier: %s',
                        $workerId,
                        trim($process->getErrorOutput()) ?: trim($process->getOutput()),
                    ));
                }
            }

            if (microtime(true) > $deadline) {
                throw new RuntimeException('Timed out waiting for workers to reach the barrier.');
            }

            usleep(20_000);
        }
    }
}
