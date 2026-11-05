<?php

namespace Tests\Support\Concurrency;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Tests\TestCase;

/**
 * Boots the application without any .env file and verifies the target before connecting.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->useEnvironmentPath(__DIR__);
        $app->make(Kernel::class)->bootstrap();

        ConcurrencyHarness::assertCurrentTargetIsSafe();

        return $app;
    }
}
