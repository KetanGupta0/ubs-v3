<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class College extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'mou_signed_on' => 'date',
            'mou_expires_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(UserProfile::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function mouIsExpiring(int $withinDays = 60): bool
    {
        return $this->mou_expires_on !== null
            && $this->mou_expires_on->isFuture()
            && $this->mou_expires_on->diffInDays(now()) <= $withinDays;
    }

    public function mouHasExpired(): bool
    {
        return $this->mou_expires_on !== null && $this->mou_expires_on->isPast();
    }
}
