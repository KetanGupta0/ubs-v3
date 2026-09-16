<?php

namespace App\Models;

use App\Enums\AuthEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthAuditLog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'event' => AuthEvent::class,
            'succeeded' => 'boolean',
            'context' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
