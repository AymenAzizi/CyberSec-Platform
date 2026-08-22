<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A platform-raised security alert that may originate from a scan, a finding,
 * a worker, or a manual triage action.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $scan_id
 * @property int|null $finding_id
 * @property string $type
 * @property string $severity
 * @property string $title
 * @property string $description
 * @property string $source
 * @property bool $acknowledged
 * @property int|null $acknowledged_by
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SecurityAlert extends BaseModel
{
    /** Severity buckets (aligned with {@see Finding} severities). */
    public const SEVERITY_INFO = 'info';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    /** Common alert sources. */
    public const SOURCE_SYSTEM = 'system';

    public const SOURCE_SCAN = 'scan';

    public const SOURCE_AI = 'ai';

    public const SOURCE_MANUAL = 'manual';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'scan_id',
        'finding_id',
        'type',
        'severity',
        'title',
        'description',
        'source',
        'acknowledged',
        'acknowledged_by',
        'acknowledged_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string,string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'acknowledged' => 'boolean',
            'acknowledged_at' => 'datetime',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The project this alert belongs to.
     *
     * @return BelongsTo<Project,$this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The scan that triggered the alert (if any).
     *
     * @return BelongsTo<Scan,$this>
     */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    /**
     * The finding that triggered the alert (if any).
     *
     * @return BelongsTo<Finding,$this>
     */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }

    /**
     * The user who acknowledged the alert.
     *
     * @return BelongsTo<User,$this>
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes & accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to alerts that have not yet been acknowledged.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUnacknowledged(Builder $query): Builder
    {
        return $query->where('acknowledged', false);
    }

    /**
     * Scope to alerts at or above the given severity bucket.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAtLeastSeverity(Builder $query, string $severity): Builder
    {
        return $query->whereIn('severity', Finding::SEVERITY_RANK !== []
            ? array_keys(array_filter(
                Finding::SEVERITY_RANK,
                static fn (int $rank): bool => $rank >= (Finding::SEVERITY_RANK[$severity] ?? 0),
            ))
            : [$severity]);
    }

    /**
     * Mark the alert as acknowledged by the given user.
     */
    public function acknowledge(User $user): bool
    {
        return $this->update([
            'acknowledged' => true,
            'acknowledged_by' => $user->id,
            'acknowledged_at' => now(),
        ]);
    }

    /**
     * Whether the alert is still pending acknowledgement.
     */
    public function getIsOpenAttribute(): bool
    {
        return ! $this->acknowledged;
    }
}
