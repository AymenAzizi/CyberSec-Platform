<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A directed, typed edge in the project knowledge graph.
 *
 * @property int $id
 * @property int $source_asset_id
 * @property int $target_asset_id
 * @property string $type
 * @property array|null $properties
 * @property float $weight
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AssetRelation extends BaseModel
{
    /** Relation (edge) types connecting two assets in the graph. */
    public const TYPE_HAS_PORT = 'has_port';

    public const TYPE_HOSTS = 'hosts';

    public const TYPE_EXPOSES = 'exposes';

    public const TYPE_HAS_VULNERABILITY = 'has_vulnerability';

    public const TYPE_IMPACTS = 'impacts';

    public const TYPE_CONNECTS_TO = 'connects_to';

    /** All supported relation types. */
    public const TYPES = [
        self::TYPE_HAS_PORT,
        self::TYPE_HOSTS,
        self::TYPE_EXPOSES,
        self::TYPE_HAS_VULNERABILITY,
        self::TYPE_IMPACTS,
        self::TYPE_CONNECTS_TO,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'source_asset_id',
        'target_asset_id',
        'type',
        'properties',
        'weight',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string,string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            ...$this->jsonbCasts(['properties']),
            'weight' => 'float',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The asset the relation originates from.
     *
     * @return BelongsTo<Asset,$this>
     */
    public function sourceAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'source_asset_id');
    }

    /**
     * The asset the relation points to.
     *
     * @return BelongsTo<Asset,$this>
     */
    public function targetAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'target_asset_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes & accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to relations of a given edge type.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to relations originating from the given asset.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFromAsset(Builder $query, int $assetId): Builder
    {
        return $query->where('source_asset_id', $assetId);
    }

    /**
     * Scope to relations pointing to the given asset.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeToAsset(Builder $query, int $assetId): Builder
    {
        return $query->where('target_asset_id', $assetId);
    }

    /**
     * Whether this edge represents a vulnerability relationship.
     */
    public function getIsVulnerabilityAttribute(): bool
    {
        return $this->type === self::TYPE_HAS_VULNERABILITY;
    }
}
