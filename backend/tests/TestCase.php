<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        // Test configuration comes from phpunit.xml, never the real application's .env.
        $app->useEnvironmentPath(__DIR__.'/Support');
        $app->loadEnvironmentFrom('.env.testing');
        $app->make(Kernel::class)->bootstrap();
        if (env('MASAL_NATIVE_DB_ACCEPTANCE') === '1' && (
            config('database.default') !== 'mysql'
            || config('database.connections.mysql.database') !== 'dananiriq_masalverify'
            || ! $app->environment('testing')
        )) {
            throw new \RuntimeException('Native acceptance is restricted to the disposable verification database.');
        }

        return $app;
    }
}
