<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single message within a {@see ChatSession}.
 *
 * @property int $id
 * @property int $chat_session_id
 * @property string $role
 * @property string $content
 * @property array|null $citations
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ChatMessage extends BaseModel
{
    /** Conversational role identifiers (OpenAI-style). */
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_SYSTEM = 'system';

    public const ROLES = [
        self::ROLE_USER,
        self::ROLE_ASSISTANT,
        self::ROLE_SYSTEM,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'chat_session_id',
        'role',
        'content',
        'citations',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string,string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            ...$this->jsonbCasts(['citations']),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The chat session this message belongs to.
     *
     * @return BelongsTo<ChatSession,$this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'chat_session_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes & accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to messages authored with a given conversational role.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithRole(Builder $query, string $role): Builder
    {
        return $query->where('role', $role);
    }

    /**
     * Scope ordering messages chronologically (oldest first).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('id');
    }

    /**
     * Whether the message was produced by the assistant.
     */
    public function getIsAssistantAttribute(): bool
    {
        return $this->role === self::ROLE_ASSISTANT;
    }
}
