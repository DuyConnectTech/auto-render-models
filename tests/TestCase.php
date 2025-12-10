<?php

namespace Connecttech\AutoRenderModels\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Connecttech\AutoRenderModels\Providers\AutoRenderModelsServiceProvider;
use Connecttech\AutoRenderModels\Meta\SchemaManager;
use Connecttech\AutoRenderModels\Meta\MySql\Schema as MySqlSchema;
use Illuminate\Database\SQLiteConnection;

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

        // Register SQLite connection to use MySQL Schema Mapper (Just to bypass SchemaManager constructor check)
        // Since we disable enum generation in tests, the actual mapper logic won't be invoked.
        SchemaManager::register(SQLiteConnection::class, MySqlSchema::class);
    }
}
