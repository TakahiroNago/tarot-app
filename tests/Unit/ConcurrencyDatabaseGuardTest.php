<?php

use Tests\Support\Concurrency\ConcurrencyDatabaseGuard;

/**
 * @return array<string, mixed>
 */
function safeConcurrencyTarget(): array
{
    return [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => '33307',
        'database' => 'tarot_ec_concurrency_test',
        'url' => null,
        'unix_socket' => '',
    ];
}

test('the disposable concurrency target is accepted', function () {
    expect(ConcurrencyDatabaseGuard::targetViolations(safeConcurrencyTarget()))->toBe([]);
});

test('any other database target is refused', function (array $override) {
    expect(fn () => ConcurrencyDatabaseGuard::assertSafeTarget([...safeConcurrencyTarget(), ...$override]))
        ->toThrow(RuntimeException::class, 'Refusing to use this database');
})->with([
    'sqlite driver' => [['driver' => 'sqlite']],
    'missing driver' => [['driver' => null]],
    'localhost socket fallback' => [['host' => 'localhost']],
    'remote host' => [['host' => 'db.example.com']],
    'all interfaces' => [['host' => '0.0.0.0']],
    'existing local MySQL port' => [['port' => '3306']],
    'missing port' => [['port' => null]],
    'application database' => [['database' => 'tarot_app']],
    'production database' => [['database' => 'tarot_production']],
    'suffix after the test name' => [['database' => 'tarot_ec_concurrency_test_prod']],
    'prefix before the test name' => [['database' => 'prod_tarot_ec_concurrency_test']],
    'connection URL' => [['url' => 'mysql://user:pass@db.example.com/tarot']],
    'connection URL of "0"' => [['url' => '0']],
    'unix socket' => [['unix_socket' => '/var/run/mysqld/mysqld.sock']],
    'read host override' => [['read' => ['host' => ['db.example.com']]]],
    'write host override' => [['write' => ['host' => ['db.example.com']]]],
]);

test('empty read and write settings do not override the host', function () {
    expect(ConcurrencyDatabaseGuard::targetViolations([...safeConcurrencyTarget(), 'read' => [], 'write' => null]))
        ->toBe([]);
});

test('rebuilding is allowed for an empty database or one carrying the harness marker', function (array $tables) {
    ConcurrencyDatabaseGuard::assertSchemaMayBeRebuilt('tarot_ec_concurrency_test', $tables);

    expect(true)->toBeTrue();
})->with([
    'empty' => [[]],
    'marked' => [['users', ConcurrencyDatabaseGuard::MARKER_TABLE]],
]);

test('rebuilding is refused for unmarked tables or a different connected database', function (string $database, array $tables) {
    expect(fn () => ConcurrencyDatabaseGuard::assertSchemaMayBeRebuilt($database, $tables))
        ->toThrow(RuntimeException::class, 'Refusing to rebuild the schema');
})->with([
    'unmarked tables' => ['tarot_ec_concurrency_test', ['users']],
    'different database' => ['tarot_app', []],
]);
