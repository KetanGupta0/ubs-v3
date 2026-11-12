<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Is this a person": Cloudflare Turnstile, when its keys are set.
 *
 * Forms that make accounts carry a honeypot and a rate limit regardless. This
 * is the extra check on top, off until the owner adds the keys to the
 * environment. When Cloudflare cannot be reached the form still works: a
 * registration form that stops whenever a third party has a bad minute costs
 * real registrations, and the other two checks still hold.
 */
class HumanCheck
{
    public static function enabled(): bool
    {
        return filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret'));
    }

    /** The public half, for the page to render the widget with. Null when switched off. */
    public static function siteKey(): ?string
    {
        return self::enabled() ? config('services.turnstile.site_key') : null;
    }

    public static function passes(Request $request): bool
    {
        if (! self::enabled()) {
            return true;
        }

        $token = (string) $request->input('cf-turnstile-response', '');

        if ($token === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => config('services.turnstile.secret'),
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ]);
        } catch (Throwable $e) {
            Log::warning('Turnstile could not be reached; the form was let through on its other checks.', ['message' => $e->getMessage()]);

            return true;
        }

        if ($response->failed()) {
            Log::warning('Turnstile answered with an error; the form was let through on its other checks.', ['status' => $response->status()]);

            return true;
        }

        return (bool) $response->json('success');
    }
}
