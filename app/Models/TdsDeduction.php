<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * TDS a client deducted from one of our invoices (CL14), and the Form 16A
 * that proves it was deposited.
 */
class TdsDeduction extends Model
{
    public const STATUSES = ['declared', 'received', 'verified', 'mismatch'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rate' => 'float',
            'base' => 'integer',
            'amount' => 'integer',
            'certificate_due_on' => 'date',
            'certificate_uploaded_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function declarer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by');
    }

    public function scopeForClient(Builder $query, User $account): Builder
    {
        return $query->where('client_id', $account->id);
    }

    /** Still waiting for the client's certificate. */
    public function scopeAwaitingCertificate(Builder $query): Builder
    {
        return $query->whereIn('status', ['declared', 'mismatch']);
    }

    public function sectionLabel(): string
    {
        return config("company.tds.sections.{$this->section}.label", $this->section);
    }

    public function amountLabel(): string
    {
        return Money::display($this->amount);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'declared' => 'Form 16A awaited',
            'received' => 'Form 16A received',
            'verified' => 'Checked against 26AS',
            'mismatch' => 'Does not match 26AS',
            default => ucfirst($this->status),
        };
    }

    /**
     * The quarter of the Indian financial year a date falls in, and when the
     * deductor's Form 16A for that quarter is due: fifteen days after the
     * quarterly return, so 15 August, 15 November, 15 February and 15 June.
     *
     * @return array{quarter: string, due: Carbon}
     */
    public static function quarterOf(Carbon $date): array
    {
        $startYear = $date->month >= 4 ? $date->year : $date->year - 1;
        $label = $startYear.'-'.substr((string) ($startYear + 1), 2);

        [$quarter, $due] = match (true) {
            $date->month >= 4 && $date->month <= 6 => ['Q1', Carbon::create($startYear, 8, 15)],
            $date->month >= 7 && $date->month <= 9 => ['Q2', Carbon::create($startYear, 11, 15)],
            $date->month >= 10 => ['Q3', Carbon::create($startYear + 1, 2, 15)],
            default => ['Q4', Carbon::create($startYear + 1, 6, 15)],
        };

        return ['quarter' => "{$label} {$quarter}", 'due' => $due];
    }
}
