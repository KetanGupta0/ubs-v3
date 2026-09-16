<?php

namespace App\Services\Auth;

use App\Models\TwoFactorSecret;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

/**
 * Time based one time passwords from an authenticator app.
 *
 * Setup is two steps on purpose: a secret is created, and only becomes
 * enforced once the user proves their app is generating the right codes. A
 * one step version would lock out anyone whose clock is wrong or who closes
 * the tab before scanning.
 */
class TwoFactorService
{
    /**
     * Windows of 30 seconds accepted either side of now, to tolerate a phone
     * clock that has drifted. One window is the usual compromise between
     * usability and keeping the guessing surface small.
     */
    protected const WINDOW = 1;

    public function __construct(protected Google2FA $engine) {}

    /**
     * Begin setup, returning the record holding the new secret.
     *
     * Calling this again before confirmation replaces the secret, so a user who
     * lost the QR code can simply start over.
     */
    public function beginSetup(User $user): TwoFactorSecret
    {
        $secret = $this->engine->generateSecretKey(32);

        return TwoFactorSecret::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['secret' => $secret, 'confirmed_at' => null, 'recovery_codes' => null],
        );
    }

    /**
     * Finish setup by checking a code from the user's app.
     *
     * @return array<int, string>|null The recovery codes, shown once, or null
     *                                 if the code did not match.
     */
    public function confirmSetup(User $user, string $code): ?array
    {
        $record = $this->recordFor($user);

        if (! $record || $record->isConfirmed() || ! $this->verifyCode($record, $code)) {
            return null;
        }

        $record->forceFill(['confirmed_at' => now()])->save();

        return $record->regenerateRecoveryCodes();
    }

    public function disable(User $user): void
    {
        $user->twoFactorSecret()->delete();
        $user->unsetRelation('twoFactorSecret');
    }

    /**
     * Check a challenge response, accepting either an app code or a recovery
     * code, since someone who has lost their phone has only the latter.
     */
    public function challenge(User $user, string $code): bool
    {
        $record = $this->recordFor($user);

        if (! $record || ! $record->isConfirmed()) {
            return false;
        }

        if ($this->verifyCode($record, $code)) {
            $record->forceFill(['last_used_at' => now()])->save();

            return true;
        }

        return $record->consumeRecoveryCode($code);
    }

    public function isRecoveryCode(string $code): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9]{6}-[A-Za-z0-9]{6}$/', trim($code));
    }

    /** The otpauth:// URI an authenticator app scans. */
    public function provisioningUri(User $user, TwoFactorSecret $record): string
    {
        return $this->engine->getQRCodeUrl(
            config('app.name'),
            $user->email ?? $user->mobile ?? (string) $user->id,
            $record->secret,
        );
    }

    /**
     * The QR code as inline SVG.
     *
     * Rendered server side and embedded in the response rather than pointing at
     * a chart service, because the secret must never leave the application.
     */
    public function qrCodeSvg(string $uri): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 0), new SvgImageBackEnd));

        return $writer->writeString($uri);
    }

    /**
     * Read the record from the database rather than the relation cache.
     *
     * Setup spans two requests, and the model instance handed to the second one
     * may still hold the "no secret yet" it cached during the first. Anything
     * that turns two factor on or checks a challenge has to see the current
     * row, not a remembered absence.
     */
    protected function recordFor(User $user): ?TwoFactorSecret
    {
        return $user->twoFactorSecret()->first();
    }

    protected function verifyCode(TwoFactorSecret $record, string $code): bool
    {
        $candidate = preg_replace('/\s+/', '', $code);

        if (! preg_match('/^\d{6}$/', (string) $candidate)) {
            return false;
        }

        return (bool) $this->engine->verifyKey($record->secret, $candidate, self::WINDOW);
    }
}
