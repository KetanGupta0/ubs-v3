<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proof that somebody finished.
 *
 * Carries a public verification code, because a certificate nobody can check is
 * decoration. The code is long enough that it cannot be found by trying
 * numbers, and revoking one leaves the row in place so the check keeps working
 * and says it was withdrawn.
 */
class Certificate extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (Certificate $certificate) {
            $certificate->number ??= self::nextNumber();
            $certificate->verification_code ??= self::newCode();
        });
    }

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
            'final_percent' => 'decimal:2',
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

    public function isValid(): bool
    {
        return $this->revoked_at === null;
    }

    public function verificationUrl(): string
    {
        return url("/verify/{$this->verification_code}");
    }

    public static function nextNumber(): string
    {
        $year = now()->year;
        $last = static::query()
            ->where('number', 'like', "UBS/C/{$year}/%")
            ->orderByDesc('id')
            ->value('number');

        $sequence = $last ? (int) str($last)->afterLast('/')->toString() : 0;

        return sprintf('UBS/C/%d/%04d', $year, $sequence + 1);
    }

    public static function newCode(): string
    {
        // Upper case and unambiguous, because people read these off paper and
        // type them in. No O, no 0, no I, no 1.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $code = collect(range(1, 12))
                ->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])
                ->join('');
        } while (static::query()->where('verification_code', $code)->exists());

        return $code;
    }
}
