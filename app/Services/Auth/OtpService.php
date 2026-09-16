<?php

namespace App\Services\Auth;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Enums\OtpResult;
use App\Models\OtpCode;
use App\Models\User;
use App\Notifications\OneTimeCodeNotification;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Issues and checks one time codes.
 *
 * Codes are stored hashed, expire, are single use, and are capped at a small
 * number of guesses. A six digit code is only safe because all four of those
 * hold at once, so none of them is optional.
 */
class OtpService
{
    /** Guesses allowed against one issued code before it is burnt. */
    public const MAX_ATTEMPTS = 5;

    /** Seconds a person must wait before asking for another code. */
    public const RESEND_COOLDOWN = 60;

    /** Codes one destination may request per hour. */
    public const HOURLY_LIMIT = 5;

    public function __construct(protected SmsSender $sms) {}

    /**
     * Seconds remaining before another code may be requested, or zero.
     */
    public function cooldownFor(string $destination, OtpPurpose $purpose): int
    {
        return RateLimiter::availableIn($this->cooldownKey($destination, $purpose));
    }

    public function hasReachedHourlyLimit(string $destination, OtpPurpose $purpose): bool
    {
        return RateLimiter::tooManyAttempts($this->hourlyKey($destination, $purpose), self::HOURLY_LIMIT);
    }

    /**
     * Create and deliver a code.
     *
     * Any earlier unused code for the same destination and purpose is consumed
     * first, so only the most recent code ever works. Otherwise requesting a
     * second code would widen the guessing surface rather than replace it.
     */
    public function issue(
        ?User $user,
        string $destination,
        OtpChannel $channel,
        OtpPurpose $purpose,
        ?string $ip = null,
    ): OtpCode {
        OtpCode::query()
            ->where('destination', $destination)
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $plain = $this->generateCode();

        $otp = OtpCode::query()->create([
            'user_id' => $user?->id,
            'destination' => $destination,
            'channel' => $channel,
            'purpose' => $purpose,
            'code_hash' => Hash::make($plain),
            'expires_at' => now()->addMinutes($purpose->lifetimeMinutes()),
            'request_ip' => $ip,
        ]);

        RateLimiter::hit($this->cooldownKey($destination, $purpose), self::RESEND_COOLDOWN);
        RateLimiter::hit($this->hourlyKey($destination, $purpose), 3600);

        $this->deliver($user, $destination, $channel, $purpose, $plain);

        return $otp;
    }

    /**
     * Check a code the user typed.
     *
     * A correct code is consumed immediately, and an incorrect one increments
     * the attempt counter on that specific code rather than a global counter,
     * so one person's mistakes cannot lock out another.
     */
    public function verify(string $destination, OtpPurpose $purpose, string $code): OtpResult
    {
        $otp = OtpCode::query()
            ->where('destination', $destination)
            ->where('purpose', $purpose->value)
            ->latest('id')
            ->first();

        if (! $otp) {
            return OtpResult::NotFound;
        }

        if ($otp->isConsumed()) {
            return OtpResult::AlreadyUsed;
        }

        if ($otp->isExpired()) {
            return OtpResult::Expired;
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            $otp->forceFill(['consumed_at' => now()])->save();

            return OtpResult::TooManyAttempts;
        }

        if (! Hash::check(trim($code), $otp->code_hash)) {
            $otp->increment('attempts');

            return OtpResult::Incorrect;
        }

        $otp->forceFill(['consumed_at' => now()])->save();

        RateLimiter::clear($this->cooldownKey($destination, $purpose));

        return OtpResult::Valid;
    }

    /** The user the most recent code for this destination belongs to. */
    public function userFor(string $destination, OtpPurpose $purpose): ?User
    {
        return OtpCode::query()
            ->where('destination', $destination)
            ->where('purpose', $purpose->value)
            ->latest('id')
            ->first()?->user;
    }

    protected function deliver(
        ?User $user,
        string $destination,
        OtpChannel $channel,
        OtpPurpose $purpose,
        string $code,
    ): void {
        $minutes = $purpose->lifetimeMinutes();

        if ($channel === OtpChannel::Sms) {
            $this->sms->send(
                $destination,
                "{$code} is your Unboundbyte verification code. It expires in {$minutes} minutes. Do not share it with anyone.",
                [
                    'template_id' => config('services.sms.msg91.otp_template_id'),
                    'variables' => ['OTP' => $code, 'MINUTES' => $minutes],
                ],
            );

            return;
        }

        $notification = new OneTimeCodeNotification($code, $purpose, $minutes);

        $user
            ? $user->notify($notification)
            : Notification::route('mail', $destination)->notify($notification);
    }

    /**
     * Six digits, from a cryptographically secure source.
     *
     * `random_int` rather than `rand`, because a predictable code is the same
     * as no code at all.
     */
    protected function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    protected function cooldownKey(string $destination, OtpPurpose $purpose): string
    {
        return 'otp:cooldown:'.$purpose->value.':'.sha1($destination);
    }

    protected function hourlyKey(string $destination, OtpPurpose $purpose): string
    {
        return 'otp:hourly:'.$purpose->value.':'.sha1($destination);
    }
}
