<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $description
 * @property string $status
 * @property array|null $scope_config
 * @property string|null $authorization_document
 * @property string|null $client_name
 * @property string|null $client_logo
 * @property string $branding_color
 * @property Carbon|null $authorized_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Project extends BaseModel
{
    /** Lifecycle status values used by the platform. */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ARCHIVED = 'archived';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'description',
        'status',
        'scope_config',
        'authorization_document',
        'client_name',
        'client_logo',
        'branding_color',
        'authorized_at',
        'expires_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string,string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            ...$this->jsonbCasts(['scope_config']),
            'authorized_at' => 'datetime',
            'expires_at' => 'datetime',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The user who owns this engagement.
     *
     * @return BelongsTo<User,$this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Targets declared within the project's authorized scope.
     *
     * @return HasMany<Target,$this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(Target::class);
    }

    /**
     * All scans ever executed under this project.
     *
     * @return HasMany<Scan,$this>
     */
    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }

    /**
     * Findings aggregated across all scans for this project.
     *
     * @return HasMany<Finding,$this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    /**
     * Generated reports for this project.
     *
     * @return HasMany<Report,$this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /**
     * Security alerts raised against this project.
     *
     * @return HasMany<SecurityAlert,$this>
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(SecurityAlert::class);
    }

    /**
     * Alias for {@see alerts()} for semantic clarity.
     *
     * @return HasMany<SecurityAlert,$this>
     */
    public function securityAlerts(): HasMany
    {
        return $this->alerts();
    }

    /**
     * Knowledge-graph assets discovered for this project.
     *
     * @return HasMany<Asset,$this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes & accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to projects that are currently active (not paused, completed or archived).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Whether the engagement is currently within its authorization window.
     */
    public function getIsAuthorizedAttribute(): bool
    {
        if (! $this->authorized_at || $this->authorized_at->isFuture()) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
