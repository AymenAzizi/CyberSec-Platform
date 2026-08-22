<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $avatar
 * @property bool $is_active
 * @property bool $two_factor_enabled
 * @property string|null $two_factor_secret
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property int $quota_scans_per_day
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;

    /**
     * Default daily scan quota applied when a user is created without one.
     */
    public const DEFAULT_QUOTA_SCANS_PER_DAY = 20;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
        'is_active',
        'two_factor_enabled',
        'two_factor_secret',
        'last_login_at',
        'last_login_ip',
        'quota_scans_per_day',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string,string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'two_factor_enabled' => 'boolean',
            'quota_scans_per_day' => 'integer',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Projects owned by this user (the engagement lead).
     *
     * @return HasMany<Project,$this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Scans launched by this user.
     *
     * @return HasMany<Scan,$this>
     */
    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }

    /**
     * Audit-log entries produced by this user.
     *
     * @return HasMany<AuditLog,$this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Remediation scripts authored by this user.
     *
     * @return HasMany<RemediationScript,$this>
     */
    public function remediationScripts(): HasMany
    {
        return $this->hasMany(RemediationScript::class);
    }

    /**
     * Chat sessions owned by this user.
     *
     * @return HasMany<ChatSession,$this>
     */
    public function chatSessions(): HasMany
    {
        return $this->hasMany(ChatSession::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Role helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Whether the user carries the platform administrator role.
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * Whether the user is a security analyst who can launch scans and edit findings.
     */
    public function isAnalyst(): bool
    {
        return $this->hasRole('analyst');
    }

    /**
     * Whether the user is a client with read-only access to their project scope.
     */
    public function isClient(): bool
    {
        return $this->hasRole('client');
    }

    /**
     * Whether the user is a compliance auditor (read-only, full visibility).
     */
    public function isAuditor(): bool
    {
        return $this->hasRole('auditor');
    }

    /*
    |--------------------------------------------------------------------------
    | Quota
    |--------------------------------------------------------------------------
    */

    /**
     * Number of scans launched by this user during the current calendar day.
     *
     * Uses a UTC date boundary to remain consistent across distributed workers.
     */
    public function dailyScanCount(): int
    {
        return (int) $this->scans()
            ->whereDate('created_at', Carbon::today(config('app.timezone')))
            ->count();
    }

    /**
     * Whether the user still has scan quota available for the current day.
     *
     * Administrators always have quota left so they can triage incidents.
     */
    public function hasQuotaLeft(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->dailyScanCount() < (int) $this->quota_scans_per_day;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes / accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to active users only (account not disabled).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Human-readable label suitable for audit trails and reports.
     */
    public function getDisplayNameAttribute(): string
    {
        return trim($this->name)." <{$this->email}>";
    }
}
