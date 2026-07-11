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
     * Determine the correct timestamped migration file name for publication.
     */
    protected function getMigrationFileName(string $migrationFileName): string
    {
        $timestamp = date('Y_m_d_His');

        return $this->app->databasePath('migrations/' . $timestamp . '_' . $migrationFileName);
    }
}
