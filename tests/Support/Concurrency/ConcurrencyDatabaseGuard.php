<?php

namespace Tests\Support\Concurrency;

use RuntimeException;

/**
 * Refuses every database target except the disposable concurrency-test container.
 *
 * The target check needs no connection, so it runs before a worker or the test process connects.
 * The schema check then proves the connected database is empty or was prepared by this harness.
 */
final class ConcurrencyDatabaseGuard
{
    public const REQUIRED_HOST = '127.0.0.1';

    public const REQUIRED_PORT = 33307;

    public const REQUIRED_DATABASE = 'tarot_ec_concurrency_test';

    public const MARKER_TABLE = 'concurrency_harness_marker';

    /**
     * @param  array<string, mixed>  $connection
     */
    public static function assertSafeTarget(array $connection): void
    {
        $violations = self::targetViolations($connection);

        if ($violations !== []) {
            throw new RuntimeException('Refusing to use this database for concurrency tests: '.implode('; ', $violations));
        }
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array<int, string>
     */
    public static function targetViolations(array $connection): array
    {
        $violations = [];

        if (($connection['driver'] ?? null) !== 'mysql') {
            $violations[] = 'the driver must be mysql';
        }
        if (($connection['host'] ?? null) !== self::REQUIRED_HOST) {
            $violations[] = 'the host must be '.self::REQUIRED_HOST;
        }
        if ((string) ($connection['port'] ?? '') !== (string) self::REQUIRED_PORT) {
            $violations[] = 'the port must be '.self::REQUIRED_PORT;
        }
        if (($connection['database'] ?? null) !== self::REQUIRED_DATABASE) {
            $violations[] = 'the database must be '.self::REQUIRED_DATABASE;
        }
        if (self::hasValue($connection, 'url')) {
            $violations[] = 'a connection URL must not be set';
        }
        if (self::hasValue($connection, 'unix_socket')) {
            $violations[] = 'a unix socket must not be set';
        }
        if (self::hasValue($connection, 'read') || self::hasValue($connection, 'write')) {
            $violations[] = 'read and write hosts must not be set because they override the host';
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private static function hasValue(array $connection, string $key): bool
    {
        return ! in_array($connection[$key] ?? null, [null, '', []], true);
    }

    /**
     * @param  array<int, string>  $tableNames
     */
    public static function assertSchemaMayBeRebuilt(string $connectedDatabase, array $tableNames): void
    {
        if ($connectedDatabase !== self::REQUIRED_DATABASE) {
            throw new RuntimeException('Refusing to rebuild the schema: the server reports a different database.');
        }

        if ($tableNames !== [] && ! in_array(self::MARKER_TABLE, $tableNames, true)) {
            throw new RuntimeException('Refusing to rebuild the schema: tables exist without the harness marker.');
        }
    }
}
