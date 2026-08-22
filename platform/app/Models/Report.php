<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Generated, signed security assessment report.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $scan_id
 * @property string $title
 * @property string|null $executive_summary
 * @property array|null $technical_details
 * @property array|null $recommendations
 * @property array|null $ai_analysis
 * @property array|null $remediation_scripts
 * @property array|null $sbom
 * @property array|null $graph_snapshot
 * @property string $format
 * @property string|null $file_path
 * @property string|null $signature
 * @property Carbon|null $generated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Report extends BaseModel
{
    /** Supported report output formats. */
    public const FORMAT_PDF = 'pdf';

    public const FORMAT_HTML = 'html';

    public const FORMAT_MD = 'markdown';

    public const FORMAT_JSON = 'json';

    public const FORMATS = [
        self::FORMAT_PDF,
        self::FORMAT_HTML,
        self::FORMAT_MD,
        self::FORMAT_JSON,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'scan_id',
        'title',
        'executive_summary',
        'technical_details',
        'recommendations',
        'ai_analysis',
        'remediation_scripts',
        'sbom',
        'graph_snapshot',
        'format',
        'file_path',
        'signature',
        'generated_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string,string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            ...$this->jsonbCasts([
                'technical_details',
                'recommendations',
                'ai_analysis',
                'remediation_scripts',
                'sbom',
                'graph_snapshot',
            ]),
            'generated_at' => 'datetime',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The project this report belongs to.
     *
     * @return BelongsTo<Project,$this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The scan this report was generated from (nullable for project-wide reports).
     *
     * @return BelongsTo<Scan,$this>
     */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes & accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Scope ordering reports by descending generation date (most recent first).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('generated_at');
    }

    /**
     * Scope to reports in the given output format.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfFormat(Builder $query, string $format): Builder
    {
        return $query->where('format', $format);
    }

    /**
     * Whether the report has been cryptographically signed.
     */
    public function getIsSignedAttribute(): bool
    {
        return filled($this->signature);
    }
}
