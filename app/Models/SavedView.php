<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question somebody asks often, kept.
 *
 * The state stored here is exactly what the URL carries, so applying a view
 * and following a shared link are the same operation. Nothing about the result
 * is stored: a saved view that held rows would be a stale copy of a list.
 */
class SavedView extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['is_shared' => false, 'times_used' => 0];

    protected function casts(): array
    {
        return [
            'state' => 'array',
            'is_shared' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Somebody's own views on a screen, plus anything shared with the team. */
    public function scopeVisibleTo(Builder $query, User $user, ?string $screen = null): Builder
    {
        return $query
            ->where(fn (Builder $scope) => $scope
                ->where('user_id', $user->id)
                ->orWhere('is_shared', true))
            ->when($screen, fn (Builder $scope) => $scope->where('screen', $screen))
            ->orderByDesc('is_shared')
            ->orderBy('name');
    }

    /** The query string this view stands for. */
    public function queryString(): string
    {
        return http_build_query($this->state ?? []);
    }

    public function href(): string
    {
        $query = $this->queryString();

        return $this->screen.($query === '' ? '' : '?'.$query);
    }

    public function used(): void
    {
        $this->forceFill([
            'times_used' => $this->times_used + 1,
            'last_used_at' => now(),
        ])->save();
    }
}
