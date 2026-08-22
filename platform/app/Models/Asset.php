<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A node in the project's knowledge graph.
 *
 * Assets are typed (domain, ip, host, port, service, vulnerability, impact)
 * and connected to each other through {@see AssetRelation} edges.
 *
 * @property int $id
 * @property int $project_id
 * @property string $type
 * @property string $label
 * @property string|null $value
 * @property array|null $metadata
 * @property array|null $properties
 * @property float $risk_score
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Asset extends BaseModel
{
    /** Asset node types. */
    public const TYPE_DOMAIN = 'domain';

    public const TYPE_IP = 'ip';

    public const TYPE_HOST = 'host';

    public const TYPE_PORT = 'port';

    public const TYPE_SERVICE = 'service';

    public const TYPE_VULNERABILITY = 'vulnerability';

    public const TYPE_IMPACT = 'impact';

    /** All supported asset types. */
    public const TYPES = [
        self::TYPE_DOMAIN,
        self::TYPE_IP,
        self::TYPE_HOST,
        self::TYPE_PORT,
        self::TYPE_SERVICE,
        self::TYPE_VULNERABILITY,
        self::TYPE_IMPACT,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'type',
        'label',
        'value',
        'metadata',
        'properties',
        'risk_score',
        'first_seen_at',
        'last_seen_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string,string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            ...$this->jsonbCasts(['metadata', 'properties']),
            'risk_score' => 'float',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The project this asset belongs to.
     *
     * @return BelongsTo<Project,$this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Outgoing relations where this asset is the source node.
     *
     * @return HasMany<AssetRelation,$this>
     */
    public function sourceRelations(): HasMany
    {
        return $this->hasMany(AssetRelation::class, 'source_asset_id');
    }

    /**
     * Incoming relations where this asset is the target node.
     *
     * @return HasMany<AssetRelation,$this>
     */
    public function targetRelations(): HasMany
    {
        return $this->hasMany(AssetRelation::class, 'target_asset_id');
    }

    /**
     * Findings attached to this asset.
     *
     * @return HasMany<Finding,$this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes & accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to assets of a given type.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to assets whose risk score meets or exceeds the given threshold.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRisky(Builder $query, float $threshold = 7.0): Builder
    {
        return $query->where('risk_score', '>=', $threshold);
    }

    /**
     * Convenience accessor combining type + label for graph rendering.
     */
    public function getDisplayLabelAttribute(): string
    {
        return ucfirst($this->type).': '.$this->label;
    }
}
