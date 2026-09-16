<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record that credentials were sent, and where to.
 *
 * Kept because an account created by an administrator has its first password
 * sitting in somebody's inbox. If a client says they never received it, the
 * answer should come from a record rather than from memory. The password
 * itself is never stored here.
 */
class CredentialDelivery extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
