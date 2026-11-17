<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A credit note against one of our invoices (CL14). Like an invoice, it is a
 * tax document: once issued it is never changed or deleted.
 */
class CreditNote extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (CreditNote $note) {
            if ($note->isDirty(array_diff(array_keys($note->getAttributes()), ['pdf_path', 'updated_at']))) {
                throw new LogicException('An issued credit note cannot be changed.');
            }
        });

        static::deleting(fn () => throw new LogicException('An issued credit note cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'billed_to' => 'array',
            'billed_from' => 'array',
            'tax_breakup' => 'array',
            'issued_at' => 'datetime',
            'subtotal' => 'integer',
            'tax' => 'integer',
            'total' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }

    public function totalLabel(): string
    {
        return Money::display($this->total);
    }
}
