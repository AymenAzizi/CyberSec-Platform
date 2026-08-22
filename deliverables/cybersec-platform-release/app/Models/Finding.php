<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $scan_id
 * @property int $project_id
 * @property int|null $target_id
 * @property int|null $asset_id
 * @property string $title
 * @property string $description
 * @property string $severity
 * @property float|null $cvss_score
 * @property string|null $cvss_vector
 * @property string|null $cve_id
 * @property string|null $cwe_id
 * @property string $evidence
 * @property string|null $endpoint
 * @property string|null $affected_component
 * @property string $source_tool
 * @property string|null $remediation
 * @property string $status
 * @property bool $is_false_positive
 * @property float $impact_score
 * @property array|null $citations
 * @property Carbon|null $verified_at
 * @property string|null $verified_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Finding extends BaseModel
{
    /** CVSS severity buckets ordered from least to most critical. */
    public const SEVERITY_INFO = 'info';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    /** Ordered severity ranking used for sorting and filtering. */
    public const SEVERITY_RANK = [
        self::SEVERITY_INFO => 0,
        self::SEVERITY_LOW => 1,
        self::SEVERITY_MEDIUM => 2,
        self::SEVERITY_HIGH => 3,
        self::SEVERITY_CRITICAL => 4,
    ];

    /** Lifecycle statuses for a finding. */
    public const STATUS_NEW = 'new';

    public const STATUS_TRIAGED = 'triaged';

    public const STATUS_REMEDIATING = 'remediating';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_ACCEPTED_RISK = 'accepted_risk';

    public const STATUS_FALSE_POSITIVE = 'false_positive';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'scan_id',
        'project_id',
        'target_id',
        'asset_id',
        'title',
        'description',
        'severity',
        'cvss_score',
        'cvss_vector',
        'cve_id',
        'cwe_id',
        'evidence',
        'endpoint',
        'affected_component',
        'source_tool',
        'remediation',
        'status',
        'is_false_positive',
        'impact_score',
        'citations',
        'verified_at',
        'verified_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string,string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            ...$this->jsonbCasts(['citations']),
            'cvss_score' => 'float',
            'impact_score' => 'float',
            'is_false_positive' => 'boolean',
            'verified_at' => 'datetime',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The scan that produced this finding.
     *
     * @return BelongsTo<Scan,$this>
     */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    /**
     * The project this finding is attached to (denormalised from scan).
     *
     * @return BelongsTo<Project,$this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The targeted asset this finding applies to.
     *
     * @return BelongsTo<Target,$this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }

    /**
     * The knowledge-graph asset this finding references (nullable).
     *
     * @return BelongsTo<Asset,$this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * Remediation scripts proposed for this finding.
     *
     * @return HasMany<RemediationScript,$this>
     */
    public function remediationScripts(): HasMany
    {
        return $this->hasMany(RemediationScript::class);
    }

    /**
     * Security alerts linked to this finding.
     *
     * @return HasMany<SecurityAlert,$this>
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(SecurityAlert::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes & accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to findings at or above the given severity bucket.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAtLeastSeverity(Builder $query, string $severity): Builder
    {
        $rank = self::SEVERITY_RANK[$severity] ?? 0;

        $buckets = array_keys(array_filter(
            self::SEVERITY_RANK,
            static fn (int $r): bool => $r >= $rank,
        ));

        return $query->whereIn('severity', $buckets);
    }

    /**
     * Scope to findings that are not flagged as false positives.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNotFalsePositive(Builder $query): Builder
    {
        return $query->where('is_false_positive', false);
    }

    /**
     * Scope to findings flagged for the given CVE identifier.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithCve(Builder $query, string $cve): Builder
    {
        return $query->where('cve_id', $cve);
    }

    /**
     * Numeric severity rank (0–4) usable for sorting.
     */
    public function getSeverityRankAttribute(): int
    {
        return self::SEVERITY_RANK[$this->severity] ?? 0;
    }

    /**
     * Whether the finding still requires human triage.
     */
    public function getIsOpenAttribute(): bool
    {
        return in_array($this->status, [
            self::STATUS_NEW,
            self::STATUS_TRIAGED,
            self::STATUS_REMEDIATING,
        ], true);
    }
}
