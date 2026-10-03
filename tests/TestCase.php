<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        // This runs before RefreshDatabase can rebuild any tables.
        if (! $app->environment('testing')
            || $app['config']->get('database.default') !== 'mysql'
            || ! str_ends_with((string) $app['config']->get('database.connections.mysql.database'), '_test')
            || $app['config']->get('database.connections.mysql.url')) {
            throw new RuntimeException('Tests require MySQL, APP_ENV=testing, an isolated _test database, and no DB_URL.');
        }

        return $app;
    }
}
