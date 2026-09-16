<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\Msg91SmsSender;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsSender::class, function () {
            $config = config('services.sms');

            return match ($config['driver'] ?? 'log') {
                'msg91' => new Msg91SmsSender(
                    (string) $config['msg91']['auth_key'],
                    $config['msg91']['sender_id'] ?? null,
                    $config['msg91']['otp_template_id'] ?? null,
                ),
                default => new LogSmsSender,
            };
        });
    }

    public function boot(): void
    {
        /*
         * One password policy for the whole application, so a rule changed here
         * applies to registration, reset and the forced first change alike.
         * Compromised password checking is skipped in tests, which would
         * otherwise make the suite depend on a third party being reachable.
         */
        Password::defaults(function () {
            $rule = Password::min(10)->letters()->mixedCase()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        // Signed links in email must survive a load balancer terminating TLS.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        /*
         * Administrators pass every gate. Everything else is decided by the
         * policy or permission the check names, which is why this returns null
         * rather than false for non admins: null means "no opinion, carry on
         * checking", false would short circuit every other rule.
         */
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);
    }
}
