<?php

namespace Connecttech\AutoRenderModels\Providers;

use Connecttech\AutoRenderModels\Console\AutoRenderModelsCommand;
use Connecttech\AutoRenderModels\Model\Config;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Connecttech\AutoRenderModels\Model\Factory as ModelFactory;
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
        $this->app->singleton(ModelFactory::class, function ($app) {
            return new ModelFactory(
                $app->make('db'),
                $app->make(Filesystem::class),
                new Classify(),
                new Config($app->make('config')->get('models'))
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
