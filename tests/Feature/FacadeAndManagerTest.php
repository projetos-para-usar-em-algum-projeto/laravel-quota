<?php

declare(strict_types=1);

namespace ZaberDev\Quota\Tests\Feature;

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use ZaberDev\Quota\Drivers\CacheQuotaDriver;
use ZaberDev\Quota\Drivers\DatabaseQuotaDriver;
use ZaberDev\Quota\Events\QuotaConsumed;
use ZaberDev\Quota\Events\QuotaExceeded;
use ZaberDev\Quota\Events\QuotaReset;
use ZaberDev\Quota\Exceptions\QuotaExceededException;
use ZaberDev\Quota\Facades\Quota;
use ZaberDev\Quota\Tests\TestCase;

class FacadeAndManagerTest extends TestCase
{
    #[Test]
    public function it_resolves_cache_and_database_drivers(): void
    {
        $this->assertInstanceOf(CacheQuotaDriver::class, Quota::driver('cache'));
        $this->assertInstanceOf(DatabaseQuotaDriver::class, Quota::driver('database'));
    }

    #[Test]
    public function it_tracks_quota_using_fluent_builder_and_dispatches_events(): void
    {
        Event::fake();

        $quota = Quota::for('monthly_exports', 'user_123')->limit(5)->perMonth();

        $this->assertSame(0, $quota->used());
        $this->assertSame(5, $quota->remaining());

        $info = $quota->consume(2);

        $this->assertSame(2, $info->used);
        $this->assertSame(3, $quota->remaining());

        Event::assertDispatched(QuotaConsumed::class, static function ($event) {
            return $event->key === 'monthly_exports:user_123' && $event->amount === 2;
        });

        $quota->consume(3);
        $this->assertTrue($quota->isExceeded());

        $this->expectException(QuotaExceededException::class);
        $quota->consume();
    }

    #[Test]
    public function it_can_attempt_consumption_without_throwing(): void
    {
        Event::fake([QuotaExceeded::class]);

        $quota = Quota::for('api_calls', 1)->limit(2)->perDay();

        $this->assertTrue($quota->attempt(2));
        $this->assertFalse($quota->attempt(1));

        Event::assertDispatched(QuotaExceeded::class);
    }

    #[Test]
    public function it_can_reset_and_flush_quotas(): void
    {
        Event::fake([QuotaReset::class]);

        $quota = Quota::for('exports', 1)->limit(10)->perMonth();
        $quota->consume(5);
        $this->assertSame(5, $quota->used());

        $quota->reset();
        $this->assertSame(0, $quota->used());

        Event::assertDispatched(QuotaReset::class);
    }
}
