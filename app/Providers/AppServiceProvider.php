<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Payments\ManualGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\RazorpayGateway;
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

        /*
         * The payment provider.
         *
         * Razorpay when it is configured, and otherwise the stand in, so the
         * whole payment path can be walked in development without an account.
         * The stand in refuses to settle anything in production, so a missing
         * key is a payment that fails rather than a payment that is waved
         * through.
         */
        $this->app->singleton(PaymentGateway::class, function () {
            $config = config('services.razorpay');

            $razorpay = new RazorpayGateway(
                $config['key_id'] ?? null,
                $config['key_secret'] ?? null,
                $config['webhook_secret'] ?? null,
            );

            return $razorpay->isLive() ? $razorpay : new ManualGateway;
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
         * The owner passes every gate. Nobody else does, staff included, or the
         * permission list would be decoration again: a gate is not much of a
         * gate if holding the admin role opens it.
         *
         * null rather than false matters: null means "no opinion, carry on
         * checking", where false would short circuit every other rule.
         */
        Gate::before(fn (User $user) => $user->is_owner ? true : null);
    }
}
