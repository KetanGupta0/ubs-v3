<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A delivered project's handover pack (CL15). Never holds a credential (X6). */
class Handover extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'licences' => 'array',
            'videos' => 'array',
            'published_content' => 'array',
            'warranty_ends_on' => 'date',
            'published_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /** The latest revision has been acknowledged by the client. */
    public function isAcknowledged(): bool
    {
        return $this->isPublished() && $this->acknowledged_revision === $this->revision;
    }
}
