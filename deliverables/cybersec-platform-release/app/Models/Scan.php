<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $target_id
 * @property int $user_id
 * @property string $type
 * @property string $target_url
 * @property string $profile
 * @property int $jitter_ms
 * @property int $rate_limit_qps
 * @property string $status
 * @property array|null $tools_status
 * @property array|null $severity_counts
 * @property array|null $config
 * @property string|null $raw_output
 * @property string|null $worker_id
 * @property int $attempt
 * @property int $max_attempts
 * @property string|null $correlation_id
 * @property Carbon|null $queued_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Scan extends BaseModel
{
    /*
    |--------------------------------------------------------------------------
    | Scan-type catalogues
    |--------------------------------------------------------------------------
    */

    /** Reconnaissance phase scan types. */
    public const RECON_TYPES = ['nmap', 'nuclei', 'gobuster', 'subfinder', 'wpscan', 'osint'];

    /** Active security testing scan types. */
    public const SECURITY_TYPES = [
        'attack_detect', 'injection_full', 'injection_sql',
        'injection_cmd', 'injection_xss', 'waf_detect', 'prevention_check',
    ];

    /** Sandboxed exploitation scan types (executed in isolated containers). */
    public const SANDBOX_TYPES = ['sandbox_full', 'sandbox_sqli', 'sandbox_cmdi', 'sandbox_xss'];

    /** Aggregated list of every supported scan type. */
    public const ALL_TYPES = [...self::RECON_TYPES, ...self::SECURITY_TYPES, ...self::SANDBOX_TYPES];

    /*
    |--------------------------------------------------------------------------
    | Execution profiles
    |--------------------------------------------------------------------------
    */

    /** Available execution profiles with their human-readable labels. */
    public const PROFILES = [
        'silent' => 'Silent (IDS-evasion)',
        'balanced' => 'Balanced (default)',
        'aggressive' => 'Aggressive (requires approval)',
    ];

    /*
    |--------------------------------------------------------------------------
    | Lifecycle statuses
    |--------------------------------------------------------------------------
    */

    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** Ordered list of every possible scan lifecycle status. */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /** Statuses considered terminal (no further state transition expected). */
    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'target_id',
        'user_id',
        'type',
        'target_url',
        'profile',
        'jitter_ms',
        'rate_limit_qps',
        'status',
        'tools_status',
        'severity_counts',
        'config',
        'raw_output',
        'worker_id',
        'attempt',
        'max_attempts',
        'correlation_id',
        'queued_at',
        'started_at',
        'completed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string,string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            ...$this->jsonbCasts(['tools_status', 'severity_counts', 'config']),
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'jitter_ms' => 'integer',
            'rate_limit_qps' => 'integer',
            'attempt' => 'integer',
            'max_attempts' => 'integer',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The project this scan belongs to.
     *
     * @return BelongsTo<Project,$this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The target this scan was executed against (nullable for project-wide scans).
     *
     * @return BelongsTo<Target,$this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }

    /**
     * The user who initiated the scan.
     *
     * @return BelongsTo<User,$this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Findings produced by this scan.
     *
     * @return HasMany<Finding,$this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    /**
     * The report generated from this scan's results.
     *
     * @return HasOne<Report,$this>
     */
    public function report(): HasOne
    {
        return $this->hasOne(Report::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to scans of a given type.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to scans executed under a given profile.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithProfile(Builder $query, string $profile): Builder
    {
        return $query->where('profile', $profile);
    }

    /**
     * Scope to scans currently in the given status.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to scans that are still in-flight (queued or running).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_QUEUED, self::STATUS_RUNNING]);
    }

    /**
     * Scope to scans that have reached a terminal state.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereIn('status', self::TERMINAL_STATUSES);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors & helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Wall-clock duration of the scan in seconds.
     *
     * Returns null when the scan has not yet started or finished, so callers
     * can distinguish "no data yet" from a sub-second execution.
     */
    protected function getDurationAttribute(): ?int
    {
        if (! $this->started_at || ! $this->completed_at) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->completed_at);
    }

    /**
     * Public method form of the {@see $duration} accessor.
     */
    public function duration(): ?int
    {
        return $this->duration;
    }

    /**
     * Whether the scan has reached a terminal state.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * Whether the scan can be safely retried given remaining attempts.
     */
    public function canRetry(): bool
    {
        return $this->status === self::STATUS_FAILED
            && $this->attempt < $this->max_attempts;
    }
}
