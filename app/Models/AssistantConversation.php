<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A visitor's conversation with the assistant on the public site (§9.2).
 * Deleted with its messages once it is older than the period set in the
 * panel; a lead it produced is a lead, and stays.
 */
class AssistantConversation extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['visitor_messages' => 0, 'input_tokens' => 0, 'output_tokens' => 0];

    protected function casts(): array
    {
        return [
            'lead_offer' => 'array',
            'last_message_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class)->orderBy('id');
    }

    public function gaps(): HasMany
    {
        return $this->hasMany(AssistantGap::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public static function findByToken(?string $token): ?self
    {
        return is_string($token) && strlen($token) === 40
            ? self::query()->where('token_hash', hash('sha256', $token))->first()
            : null;
    }

    public function isClosed(): bool
    {
        return in_array($this->outcome, ['capped', 'refused'], true);
    }
}
