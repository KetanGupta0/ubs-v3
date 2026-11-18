<?php

namespace App\Listeners;

use App\Services\Shop\CartStore;
use Illuminate\Auth\Events\Login;

/**
 * Whatever a guest put in the cart comes with them when they sign in, by
 * whichever route (§4): a password, a code, Google, or the checkout's own code.
 */
class MergeGuestCart
{
    public function __construct(protected CartStore $carts) {}

    public function handle(Login $event): void
    {
        // A token sign in from the mobile app has no session, and so no guest cart.
        if (request()->hasSession()) {
            $this->carts->mergeGuestCartInto($event->user);
        }
    }
}
