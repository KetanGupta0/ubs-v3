<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

/**
 * A user's authenticator app secret and recovery codes.
 *
 * The secret is encrypted at rest. Recovery codes are stored hashed, so a
 * database read cannot yield a working code, and they are shown exactly once
 * at the moment they are generated.
 */
class TwoFactorSecret extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'recovery_codes' => 'array',
            'confirmed_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /** @return array<int, string> The plain codes, to show the user once. */
    public function regenerateRecoveryCodes(int $count = 8): array
    {
        $plain = collect(range(1, $count))
            ->map(fn () => sprintf('%s-%s', strtoupper(bin2hex(random_bytes(3))), strtoupper(bin2hex(random_bytes(3)))))
            ->all();

        $this->forceFill([
            'recovery_codes' => array_map(fn (string $code) => Hash::make($code), $plain),
        ])->save();

        return $plain;
    }

    /**
     * Consume a recovery code. Each is single use, so a stolen printout stops
     * working the moment its codes are spent.
     */
    public function consumeRecoveryCode(string $candidate): bool
    {
        $codes = $this->recovery_codes ?? [];
        $normalised = strtoupper(trim($candidate));

        foreach ($codes as $index => $hash) {
            if (Hash::check($normalised, $hash)) {
                unset($codes[$index]);

                $this->forceFill([
                    'recovery_codes' => array_values($codes),
                    'last_used_at' => now(),
                ])->save();

                return true;
            }
        }

        return false;
    }

    public function remainingRecoveryCodes(): int
    {
        return count($this->recovery_codes ?? []);
    }
}
