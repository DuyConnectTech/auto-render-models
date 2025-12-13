<?php

namespace Connecttech\AutoRenderModels\Providers;

use Connecttech\AutoRenderModels\Console\AutoRenderModelsCommand;
use Connecttech\AutoRenderModels\Console\AutoRenderTypesCommand;
use Connecttech\AutoRenderModels\Console\AutoRenderFactoryCommand;
use Connecttech\AutoRenderModels\Model\Config;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Connecttech\AutoRenderModels\Model\Factory as ModelFactory;
use Connecttech\AutoRenderModels\Model\Enum\Factory as EnumFactory;
use Connecttech\AutoRenderModels\Support\Classify;

class AutoRenderModelsServiceProvider extends ServiceProvider
{
    /**
     * @var bool
     */
    protected $defer = true;

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        // Merge the package configuration so config('models') is always an array
        // even when the user hasn't published the config file yet.
        $this->mergeConfigFrom(
            dirname(__DIR__, 2) . '/config/models.php',
            'models'
        );

        $this->registerModelFactory();
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                dirname(__DIR__, 2) . '/config/models.php' => config_path('models.php'),
            ], 'connecttech-models');

            $this->commands([
                AutoRenderModelsCommand::class,
                AutoRenderTypesCommand::class,
                AutoRenderFactoryCommand::class,
            ]);
        }
    }

    /**
     * Register Model Factory.
     *
     * @return void
     */
    protected function registerModelFactory()
    {
        $this->app->singleton(Config::class, function ($app) {
            // Ensure we always pass an array to Config to avoid TypeError when
            // config('models') is missing or not yet published.
            $modelsConfig = (array) $app->make('config')->get('models', []);

            return new Config($modelsConfig);
        });

        $this->app->singleton(ModelFactory::class, function ($app) {
            return new ModelFactory(
                $app->make('db'),
                $app->make(Filesystem::class),
                $app->make(Classify::class),
                $app->make(Config::class),
                $app->make(EnumFactory::class)
            );
        });
    }

    /**
     * @return array
     */
    public function provides()
    {
        return [ModelFactory::class];
    }
}
