<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Configuration profile that parameterises scan execution.
 *
 * Profiles drive rate-limiting, jitter, retry policy and tool flags so that
 * operators can pick the right trade-off between stealth, throughput and
 * detection risk without rewriting scan templates.
 *
 * @property int $id
 * @property string $name
 * @property string $display_name
 * @property string $description
 * @property int $rate_limit_qps
 * @property int $jitter_min_ms
 * @property int $jitter_max_ms
 * @property int $timeout_seconds
 * @property int $max_retries
 * @property bool $requires_admin_approval
 * @property array|null $tool_flags
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ScanProfile extends BaseModel
{
    /** Canonical profile names referenced by scans. */
    public const NAME_SILENT = 'silent';

    public const NAME_BALANCED = 'balanced';

    public const NAME_AGGRESSIVE = 'aggressive';

    public const NAMES = [
        self::NAME_SILENT,
        self::NAME_BALANCED,
        self::NAME_AGGRESSIVE,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'display_name',
        'description',
        'rate_limit_qps',
        'jitter_min_ms',
        'jitter_max_ms',
        'timeout_seconds',
        'max_retries',
        'requires_admin_approval',
        'tool_flags',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string,string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            ...$this->jsonbCasts(['tool_flags']),
            'rate_limit_qps' => 'integer',
            'jitter_min_ms' => 'integer',
            'jitter_max_ms' => 'integer',
            'timeout_seconds' => 'integer',
            'max_retries' => 'integer',
            'requires_admin_approval' => 'boolean',
            'is_active' => 'boolean',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes & accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to active profiles only.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Resolve a profile instance by its canonical name.
     */
    public static function byName(string $name): ?self
    {
        return static::where('name', $name)->first();
    }

    /**
     * Pick a random jitter value (in milliseconds) within the configured range.
     */
    public function sampleJitterMs(): int
    {
        $min = max(0, (int) $this->jitter_min_ms);
        $max = max($min, (int) $this->jitter_max_ms);

        if ($min === $max) {
            return $min;
        }

        return random_int($min, $max);
    }
}
