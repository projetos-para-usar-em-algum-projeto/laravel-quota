<?php

declare(strict_types=1);

namespace ZaberDev\Quota\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when a quota is explicitly reset.
 */
class QuotaReset
{
    use Dispatchable;

    /**
     * @param string $key The storage key.
     * @param string $action The action identifier.
     * @param Model|string|int|null $target The optional target entity.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $action,
        public readonly Model|string|int|null $target = null,
    ) {}
}
