<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A remediation script proposed for a finding, optionally verified and applied.
 *
 * @property int $id
 * @property int $finding_id
 * @property int $user_id
 * @property string $title
 * @property string $language
 * @property string $code
 * @property string|null $explanation
 * @property string $status
 * @property Carbon|null $verified_at
 * @property string|null $verification_log
 * @property string|null $signature
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RemediationScript extends BaseModel
{
    /** Supported scripting languages for remediation artefacts. */
    public const LANG_BASH = 'bash';

    public const LANG_ANSIBLE = 'ansible';

    public const LANG_DOCKERFILE = 'dockerfile';

    public const LANG_TERRAFORM = 'terraform';

    public const LANG_KUBERNETES = 'kubernetes';

    public const LANG_PYTHON = 'python';

    public const LANGUAGES = [
        self::LANG_BASH,
        self::LANG_ANSIBLE,
        self::LANG_DOCKERFILE,
        self::LANG_TERRAFORM,
        self::LANG_KUBERNETES,
        self::LANG_PYTHON,
    ];

    /** Lifecycle statuses for a remediation script. */
    public const STATUS_GENERATED = 'generated';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_GENERATED,
        self::STATUS_VERIFIED,
        self::STATUS_APPLIED,
        self::STATUS_REJECTED,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'finding_id',
        'user_id',
        'title',
        'language',
        'code',
        'explanation',
        'status',
        'verified_at',
        'verification_log',
        'signature',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string,string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'verified_at' => 'datetime',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The finding this script remediates.
     *
     * @return BelongsTo<Finding,$this>
     */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }

    /**
     * The user who authored the script (or AI generation request owner).
     *
     * @return BelongsTo<User,$this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes & accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to scripts in the given status.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to scripts written in the given language.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInLanguage(Builder $query, string $language): Builder
    {
        return $query->where('language', $language);
    }

    /**
     * Whether the script has been verified in a sandbox.
     */
    public function getIsVerifiedAttribute(): bool
    {
        return $this->status === self::STATUS_VERIFIED
            || $this->status === self::STATUS_APPLIED;
    }
}
