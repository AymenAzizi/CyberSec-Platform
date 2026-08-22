<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A chat session between a user and the security co-pilot assistant.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $project_id
 * @property string|null $title
 * @property array|null $context
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ChatSession extends BaseModel
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'project_id',
        'title',
        'context',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string,string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            ...$this->jsonbCasts(['context']),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * The user who owns the chat session.
     *
     * @return BelongsTo<User,$this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The project context the chat session is bound to (nullable for general chats).
     *
     * @return BelongsTo<Project,$this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The ordered messages exchanged in this session.
     *
     * @return HasMany<ChatMessage,$this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes & accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to chats bound to a given project.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->where('project_id', $projectId);
    }

    /**
     * Convenience accessor returning the most recent user message preview.
     */
    public function getLastMessagePreviewAttribute(): ?string
    {
        $message = $this->messages()->latest('id')->first();

        return $message ? mb_substr($message->content, 0, 120) : null;
    }
}
