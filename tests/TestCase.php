<?php

namespace Connecttech\AutoRenderModels\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Connecttech\AutoRenderModels\Providers\AutoRenderModelsServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            AutoRenderModelsServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }
}
