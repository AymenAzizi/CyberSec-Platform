<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Base Eloquent model for the CyberSec Platform.
 *
 * Provides shared concerns that every domain model benefits from:
 *  - A JSONB cast helper so subclasses can declare JSON columns consistently.
 *  - A `forUser` scope enforcing row-level visibility for non-admin users.
 *  - An `auditLog` helper that records structured, immutable audit trails.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 */
abstract class BaseModel extends Model
{
    /**
     * Build a cast map for JSONB columns stored as PHP arrays.
     *
     * Usage inside a model's `casts()` method:
     *   return array_merge(parent::casts(), $this->jsonbCasts(['scope_config']));
     *
     * @param  array<int,string>  $columns
     * @return array<string,string>
     */
    protected function jsonbCasts(array $columns): array
    {
        return array_fill_keys($columns, 'array');
    }

    /**
     * Scope the query to records owned by the given (or authenticated) user.
     *
     * Administrators bypass the filter and see every record. When no user is
     * authenticated, the scope returns an empty result set rather than leaking
     * rows, providing a fail-closed behaviour for misuse from a CLI or job.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForUser(Builder $query, ?User $user = null): Builder
    {
        $user ??= Auth::user();

        // Fail-closed: no authenticated user → no rows returned.
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where($this->getTable().'.user_id', $user->id);
    }

    /**
     * Persist a structured audit-log entry describing an action on this model.
     *
     * The audit log is meant to be append-only and tamper-evident; callers
     * should pass meaningful, normalised `action` strings (e.g. "scan.started",
     * "finding.acknowledged") and serialise contextual information in $details.
     *
     * @param  string  $action  Dotted action identifier (e.g. "project.created").
     * @param  array<string,mixed>  $details  Contextual payload, JSON-encoded.
     */
    public function auditLog(string $action, array $details = []): AuditLog
    {
        /** @var User|null $user */
        $user = Auth::user();

        $request = request();

        return AuditLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'entity_type' => $this->getMorphClass(),
            'entity_id' => $this->getKey(),
            'details' => $details ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
