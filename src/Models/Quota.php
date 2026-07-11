<?php

declare(strict_types=1);

namespace ZaberDev\Quota\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Eloquent model representing a stored quota period record.
 *
 * @property int $id
 * @property string $key
 * @property string $action
 * @property string|null $target_type
 * @property string|int|null $target_id
 * @property int $used
 * @property int|null $limit
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Quota extends Model
{
    use Prunable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'action',
        'target_type',
        'target_id',
        'used',
        'limit',
        'period_start',
        'period_end',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'used' => 'integer',
            'limit' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
        ];
    }

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('quotas.drivers.database.table', parent::getTable());
    }

    /**
     * Get the polymorphic target entity associated with the quota record.
     */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the prunable model query to purge expired quota periods from the database.
     */
    public function prunable(): Builder
    {
        return static::query()->where('period_end', '<=', CarbonImmutable::now());
    }
}
