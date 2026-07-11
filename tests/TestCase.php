<?php

declare(strict_types=1);

namespace ZaberDev\Quota\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use ZaberDev\Quota\QuotaServiceProvider;

class TestCase extends OrchestraTestCase
{
    /**
     * Required by Testbench when testing under prefer-lowest across PHP 8.2+ environments.
     */
    public static $latestResponse = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    protected function getPackageProviders($app): array
    {
        return [
            QuotaServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('quotas.default', 'cache');
    }

    protected function setUpDatabase(): void
    {
        Schema::create('quotas', function (Blueprint $table) {
            $table->id();
            $table->string('key')->index();
            $table->string('action')->index();
            $table->nullableMorphs('target');
            $table->unsignedBigInteger('used')->default(0);
            $table->unsignedBigInteger('limit')->nullable();
            $table->timestamp('period_start')->nullable()->index();
            $table->timestamp('period_end')->nullable()->index();
            $table->timestamps();

            $table->unique(['key', 'period_start', 'period_end'], 'quotas_key_period_unique');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });
    }
}
