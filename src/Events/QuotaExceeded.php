<?php

declare(strict_types=1);

namespace ZaberDev\Quota\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use ZaberDev\Quota\DTO\QuotaInfo;

/**
 * Dispatched when an action check or attempt exceeds the configured quota limit.
 */
class QuotaExceeded
{
    use Dispatchable;

    /**
     * @param string $key The storage key.
     * @param QuotaInfo $info The current quota DTO.
     * @param string $action The action identifier.
     * @param Model|string|int|null $target The optional target entity.
     */
    public function __construct(
        public readonly string $key,
        public readonly QuotaInfo $info,
        public readonly string $action,
        public readonly Model|string|int|null $target = null,
    ) {}
}
