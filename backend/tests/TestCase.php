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
        $database = $app['config']->get('database.connections.mysql.database');
        if (! $app->environment('testing') || $database !== 'fcontrol_test' || $app['config']->get('database.connections.mysql.url')) {
            throw new RuntimeException('Testes bloqueados: use APP_ENV=testing e o banco exclusivo fcontrol_test, sem DB_URL.');
        }

        return $app;
    }
}
