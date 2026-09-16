<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Batch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'schedule' => 'array',
            'is_published' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trainer_id');
    }

    public function seatsLeft(): ?int
    {
        return $this->capacity ? max($this->capacity - $this->seats_taken, 0) : null;
    }

    public function isNearlyFull(): bool
    {
        $left = $this->seatsLeft();

        return $left !== null && $left > 0 && $left <= 5;
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'startsOn' => $this->starts_on?->toDateString(),
            'startsOnLabel' => $this->starts_on?->format('j M Y'),
            'endsOnLabel' => $this->ends_on?->format('j M Y'),
            'schedule' => $this->schedule ?? [],
            'seatsLeft' => $this->seatsLeft(),
            'nearlyFull' => $this->isNearlyFull(),
            'status' => $this->status,
        ];
    }
}
