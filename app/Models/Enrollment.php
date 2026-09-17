<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student on one course, usually in one batch.
 *
 * `has_paid` is stored rather than derived from the ledger, because it is read
 * every time a lesson is opened and the answer does not change often. A free
 * course and a scholarship both land here as paid with nothing owing.
 */
class Enrollment extends Model
{
    public const STATUSES = ['active', 'completed', 'dropped', 'on_hold'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'active', 'source' => 'admin', 'progress_percent' => 0];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
            'completed_at' => 'datetime',
            'dropped_at' => 'datetime',
            'has_paid' => 'boolean',
            'progress_percent' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeForStudent(Builder $query, User|int $student): Builder
    {
        return $query->where('user_id', $student instanceof User ? $student->id : $student);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function statusLabel(): string
    {
        return str($this->status)->replace('_', ' ')->title()->toString();
    }
}
