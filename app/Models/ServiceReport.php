<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A month of a maintenance contract, reported to the client (CL17). */
class ServiceReport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'figures' => 'array',
            'uptime_percent' => 'float',
            'hours_used' => 'float',
            'published_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(MaintenanceContract::class, 'contract_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function monthLabel(): string
    {
        return $this->month->format('F Y');
    }
}
