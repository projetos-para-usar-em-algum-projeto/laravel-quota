<?php

declare(strict_types=1);

namespace ZaberDev\Quota\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use ZaberDev\Quota\HasQuotas;
use ZaberDev\Quota\Models\Quota as QuotaModel;
use ZaberDev\Quota\Tests\TestCase;

class TestUser extends Model
{
    use HasQuotas;

    protected $table = 'users';
    protected $guarded = [];
}

class EloquentQuotaTest extends TestCase
{
    #[Test]
    public function it_integrates_cleanly_with_eloquent_models_using_database_driver(): void
    {
        $user = TestUser::query()->create(['name' => 'Mahedi', 'email' => 'zaber@example.com']);

        $quota = $user->quota('reports')->using('database')->limit(10)->perMonth();

        $this->assertSame(0, $quota->used());
        $quota->consume(3);
        $this->assertSame(3, $quota->used());

        $this->assertDatabaseHas('quotas', [
            'action' => 'reports',
            'target_type' => TestUser::class,
            'target_id' => $user->id,
            'used' => 3,
            'limit' => 10,
        ]);

        $this->assertCount(1, $user->quotas);
    }
}
