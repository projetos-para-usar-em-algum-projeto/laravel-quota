<?php

declare(strict_types=1);

namespace ZaberDev\Quota\Tests\Unit;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZaberDev\Quota\DTO\QuotaInfo;

class QuotaInfoTest extends TestCase
{
    #[Test]
    public function it_calculates_remaining_quota_correctly(): void
    {
        $now = CarbonImmutable::now();
        $info = new QuotaInfo('test_key', 15, 50, $now->startOfMonth(), $now->endOfMonth());

        $this->assertSame(35, $info->remaining());
        $this->assertFalse($info->isExceeded());
        $this->assertTrue($info->hasCapacity(35));
        $this->assertFalse($info->hasCapacity(36));
        $this->assertSame(30.0, $info->usagePercentage());
    }

    #[Test]
    public function it_handles_unlimited_quotas(): void
    {
        $now = CarbonImmutable::now();
        $info = new QuotaInfo('test_key', 999, null, $now->startOfMonth(), $now->endOfMonth());

        $this->assertSame(PHP_INT_MAX, $info->remaining());
        $this->assertFalse($info->isExceeded());
        $this->assertTrue($info->hasCapacity(1000));
        $this->assertSame(0.0, $info->usagePercentage());
    }

    #[Test]
    public function it_converts_to_array(): void
    {
        $now = CarbonImmutable::now();
        $info = new QuotaInfo('test_key', 50, 50, $now->startOfMonth(), $now->endOfMonth());

        $array = $info->toArray();

        $this->assertSame('test_key', $array['key']);
        $this->assertSame(50, $array['used']);
        $this->assertSame(50, $array['limit']);
        $this->assertSame(0, $array['remaining']);
        $this->assertTrue($array['is_exceeded']);
        $this->assertSame(100.0, $array['usage_percentage']);
    }
}
