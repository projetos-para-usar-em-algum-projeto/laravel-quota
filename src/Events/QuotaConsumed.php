<?php

declare(strict_types=1);

namespace ZaberDev\Quota\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use ZaberDev\Quota\DTO\QuotaInfo;

/**
 * Dispatched when a quota is successfully consumed/incremented.
 */
class QuotaConsumed
{
    use Dispatchable;

    /**
     * @param string $key The storage key.
     * @param int $amount The amount of quota consumed.
     * @param QuotaInfo $info The updated quota DTO.
     * @param string $action The action identifier.
     * @param Model|string|int|null $target The optional target entity.
     */
    public function __construct(
        public readonly string $key,
        public readonly int $amount,
        public readonly QuotaInfo $info,
        public readonly string $action,
        public readonly Model|string|int|null $target = null,
    ) {}
}
