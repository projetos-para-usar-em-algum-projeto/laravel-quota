<?php

declare(strict_types=1);

namespace ZaberDev\Quota\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use ZaberDev\Quota\Exceptions\QuotaExceededException;
use ZaberDev\Quota\Facades\Quota;
use ZaberDev\Quota\Tests\TestCase;

class ConcurrencyAndLockingTest extends TestCase
{
    #[Test]
    public function cache_driver_acquire_release_and_is_locked(): void
    {
        $pending = Quota::for('test_cache_lock')->using('cache');

        $this->assertFalse($pending->isLocked());

        $acquired = $pending->acquireLock(10);
        $this->assertTrue($acquired);
        $this->assertTrue($pending->isLocked());

        // Second acquire attempt should fail
        $acquiredAgain = $pending->acquireLock(10);
        $this->assertFalse($acquiredAgain);

        $released = $pending->releaseLock();
        $this->assertTrue($released);
        $this->assertFalse($pending->isLocked());
    }

    #[Test]
    public function database_driver_acquire_release_and_is_locked(): void
    {
        $pending = Quota::for('test_db_lock')->using('database');

        $this->assertFalse($pending->isLocked());

        $acquired = $pending->acquireLock(10);
        $this->assertTrue($acquired);
        $this->assertTrue($pending->isLocked());

        // Second acquire attempt should fail
        $acquiredAgain = $pending->acquireLock(10);
        $this->assertFalse($acquiredAgain);

        $released = $pending->releaseLock();
        $this->assertTrue($released);
        $this->assertFalse($pending->isLocked());
    }

    #[Test]
    public function block_method_executes_callback_and_consumes_quota_on_success(): void
    {
        $pending = Quota::for('block_action')->limit(10)->perMonth();

        $this->assertSame(0, $pending->used());

        $executed = false;
        $result = $pending->block(function () use (&$executed) {
            $executed = true;
            return 'success_value';
        }, 2, 60);

        $this->assertTrue($executed);
        $this->assertSame('success_value', $result);
        $this->assertSame(2, $pending->used());
        $this->assertFalse($pending->isLocked());
    }

    #[Test]
    public function block_method_releases_lock_without_consuming_quota_on_exception(): void
    {
        $pending = Quota::for('error_block_action')->limit(10)->perMonth();

        try {
            $pending->block(function () {
                throw new \RuntimeException('Simulated error');
            }, 3, 60);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated error', $e->getMessage());
        }

        $this->assertFalse($pending->isLocked());
        $this->assertSame(0, $pending->used());
    }

    #[Test]
    public function block_method_throws_quota_exceeded_exception_when_already_locked(): void
    {
        $pending = Quota::for('locked_block_action')->limit(10)->perMonth();
        $pending->acquireLock(15);

        $this->expectException(QuotaExceededException::class);

        $pending->block(function () {
            return 'should_not_run';
        }, 1, 60);
    }
}
