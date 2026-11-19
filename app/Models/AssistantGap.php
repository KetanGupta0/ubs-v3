<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A question the assistant could not answer from the site: where the site's words fall short (§9.2). */
class AssistantGap extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'assistant_conversation_id');
    }
}
