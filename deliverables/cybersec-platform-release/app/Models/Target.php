<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $domain_url
 * @property string|null $ip_address
 * @property string $scope_type
 * @property string $authorization_status
 * @property string|null $authorization_document
 * @property Carbon|null $authorized_at
 * @property string|null $notes
 * @property array|null $osint_data
 * @property array|null $tech_stack
 * @property array|null $subdomains
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Target extends BaseModel
{
    /** Scope types classifying the target. */
    public const SCOPE_DOMAIN = 'domain';

    public const SCOPE_SUBDOMAIN = 'subdomain';

    public const SCOPE_IP = 'ip';

    public const SCOPE_CIDR = 'cidr';

    public const SCOPE_WILDCARD = 'wildcard';

    /** Authorization lifecycle states. */
    const AUTH_PENDING = 'pending';

    const AUTH_APPROVED = 'approved';

    const AUTH_REJECTED = 'rejected';

    const AUTH_EXPIRED = 'expired';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'name',
        'domain_url',
        'ip_address',
        'scope_type',
        'authorization_status',
        'authorization_document',
        'authorized_at',
        'notes',
        'osint_data',
        'tech_stack',
        'subdomains',
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
            ...$this->jsonbCasts(['osint_data', 'tech_stack', 'subdomains']),
            'authorized_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The parent project that owns this target.
     *
     * @return BelongsTo<Project,$this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Scans that targeted this asset.
     *
     * @return HasMany<Scan,$this>
     */
    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }

    /**
     * Findings discovered against this target.
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
     * Scope to targets with an active authorization.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAuthorized(Builder $query): Builder
    {
        return $query->where('authorization_status', self::AUTH_APPROVED);
    }

    /**
     * Scope to targets of a given scope type (domain, ip, cidr, ...).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('scope_type', $type);
    }

    /**
     * Whether the target is currently within its authorization window.
     */
    public function getIsAuthorizedAttribute(): bool
    {
        return $this->authorization_status === self::AUTH_APPROVED
            && filled($this->authorized_at)
            && ! $this->authorized_at->isFuture();
    }
}
