<?php

declare(strict_types=1);

namespace ZaberDev\Quota;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use ZaberDev\Quota\Contracts\QuotaManagerContract;
use ZaberDev\Quota\Models\Quota as QuotaModel;
use ZaberDev\Quota\Support\PendingQuota;

/**
 * Trait allowing any Eloquent model to easily track and consume action quotas.
 */
trait HasQuotas
{
    /**
     * Begin constructing a quota check targeting the current model instance.
     */
    public function quota(string $action): PendingQuota
    {
        return app(QuotaManagerContract::class)->for($action, $this);
    }

    /**
     * Get all database quota records scoped to this polymorphic target model.
     * Only active when using the "database" quota storage driver.
     */
    public function quotas(): MorphMany
    {
        return $this->morphMany(QuotaModel::class, 'target');
    }
}
