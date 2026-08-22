<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable, append-only audit trail entry.
 *
 * Entries are written by {@see BaseModel::auditLog()} and by platform
 * services that need to record security-relevant actions.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $entity_type
 * @property int|null $entity_id
 * @property array|null $details
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AuditLog extends BaseModel
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'details',
        'ip_address',
        'user_agent',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string,string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            ...$this->jsonbCasts(['details']),
            'entity_id' => 'integer',
        ]);
    }

    /**
     * Audit logs are immutable: updates and deletes are disabled at the model
     * level to preserve evidentiary integrity.
     */
    public function save(array $options = []): bool
    {
        // Only allow INSERTs; updates must never happen on an audit row.
        if ($this->exists) {
            return true;
        }

        return parent::save($options);
    }

    /**
     * Deleting audit logs is forbidden.
     */
    public function delete(): ?bool
    {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The user who triggered the audited action (nullable for system events).
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
     * Scope to audit entries targeting the given entity (type + id).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForEntity(Builder $query, string $type, int $id): Builder
    {
        return $query->where('entity_type', $type)->where('entity_id', $id);
    }

    /**
     * Scope to audit entries produced by a given action identifier.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    /**
     * Scope to audit entries whose action matches the given dotted prefix.
     *
     * Useful for retrieving every action of a domain ("scan.*", "finding.*").
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActionPrefix(Builder $query, string $prefix): Builder
    {
        return $query->where('action', 'like', rtrim($prefix, '.').'.%');
    }

    /**
     * Convenience accessor: the dotted "entity_type:entity_id" coordinate.
     */
    public function getEntityCoordinateAttribute(): ?string
    {
        if (! $this->entity_type) {
            return null;
        }

        return $this->entity_type.':'.$this->entity_id;
    }
}
