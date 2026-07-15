<?php

declare(strict_types=1);

namespace ZaberDev\Quota;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use ZaberDev\Quota\Contracts\QuotaManagerContract;
use ZaberDev\Quota\Http\Middleware\CheckQuota;

/**
 * Service provider registering Quota services, configurations, migrations, and middleware.
 */
class QuotaServiceProvider extends ServiceProvider
{
    /**
     * Register services in the application container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/quotas.php', 'quotas');

        $this->app->singleton(QuotaManagerContract::class, static function ($app) {
            return new QuotaManager($app);
        });

        $this->app->alias(QuotaManagerContract::class, 'quota');
    }

    /**
     * Bootstrap application services and publish resources.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/quotas.php' => $this->app->configPath('quotas.php'),
            ], 'quotas-config');

            $this->publishes([
                __DIR__ . '/../database/migrations/create_quotas_table.php.stub' => $this->getMigrationFileName('create_quotas_table.php'),
            ], 'quotas-migrations');

            $this->publishes([
                __DIR__ . '/../resources/boost/skills/laravel-quota' => $this->app->basePath('.ai/skills/laravel-quota'),
            ], ['quotas-skill', 'quotas-boost-skills']);

            $this->autoPublishBoostSkill();
        }

        $this->registerMiddleware();
    }

    /**
     * Register the package route middleware.
     */
    protected function registerMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('quota', CheckQuota::class);
    }

    /**
     * Automatically publish or synchronize the package AI skill when Laravel Boost is installed.
     */
    protected function autoPublishBoostSkill(): void
    {
        $boostDetected = class_exists(\Laravel\Boost\BoostServiceProvider::class)
            || is_dir($this->app->basePath('.ai/skills'));

        if (! $boostDetected) {
            return;
        }

        $source = __DIR__ . '/../resources/boost/skills/laravel-quota';
        $destination = $this->app->basePath('.ai/skills/laravel-quota');

        if (is_dir($source) && ! is_dir($destination)) {
            $this->app['files']->copyDirectory($source, $destination);
        }
    }

    /**
     * Determine the correct timestamped migration file name for publication.
     */
    protected function getMigrationFileName(string $migrationFileName): string
    {
        $timestamp = date('Y_m_d_His');

        return $this->app->databasePath('migrations/' . $timestamp . '_' . $migrationFileName);
    }
}
