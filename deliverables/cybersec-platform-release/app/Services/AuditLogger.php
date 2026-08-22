<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Centralised writer for the platform's append-only audit trail.
 *
 * The audit log is the evidentiary backbone of the platform: every
 * security-relevant mutation (project create/update/delete, scan dispatch,
 * finding acknowledge, role assignment, report export, ...) is recorded
 * with the acting user, the source IP and a structured details payload.
 *
 * Writers should prefer the model-bound {@see \App\Models\BaseModel::auditLog()}
 * helper when the acting user is the authenticated user, since it is shorter
 * and automatically captures the request context. This service is intended
 * for cases where:
 *   - The acting user is *not* the authenticated user (e.g. a job running on
 *     behalf of another user, or a system-initiated action).
 *   - The entity is not an Eloquent model (e.g. a sandbox container ID).
 *   - The action originates from a CLI/job context where `request()` is null.
 */
class AuditLogger
{
    /**
     * Record a single immutable audit-log entry.
     *
     * @param  User|null  $user  The acting user (null for system events).
     * @param  string  $action  Dotted action identifier (e.g. "scan.queued").
     * @param  Model|null  $entity  The entity affected (if any).
     * @param  array<string,mixed>  $details  Structured payload, JSON-encoded.
     * @param  Request|null  $request  Optional request override (defaults to active request).
     */
    public static function log(
        ?User $user,
        string $action,
        ?Model $entity = null,
        array $details = [],
        ?Request $request = null,
    ): AuditLog {
        $user ??= Auth::user();
        $request ??= request();

        return AuditLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'entity_type' => $entity?->getMorphClass(),
            'entity_id' => $entity?->getKey(),
            'details' => $details ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    /**
     * Record a system-level audit entry (no user, no entity).
     *
     * Useful for worker callbacks, scheduler events and infrastructure alerts.
     *
     * @param  array<string,mixed>  $details
     */
    public static function system(string $action, array $details = [], ?Request $request = null): AuditLog
    {
        return self::log(user: null, action: $action, entity: null, details: $details, request: $request);
    }
}
