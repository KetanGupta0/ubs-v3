<?php

use App\Http\Controllers\Shop\CartController;
use App\Http\Controllers\Shop\CheckoutController;
use App\Http\Controllers\Shop\DownloadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The shop (§4)
|--------------------------------------------------------------------------
|
| A cart anybody can fill. Prices are worked out on the server every time;
| what the page sends is only what was chosen. A guest proves their email
| address with a code at checkout, which is their way into an account (CL6),
| and an order is placed from a signed in client account only.
|
| Each throttle has its own prefix: an unnamed one shares its count with every
| other unnamed one for the same address, so trying two codes in the cart
| would otherwise use up the attempts for sending the checkout code.
|
*/

Route::get('/cart', [CartController::class, 'show'])->name('cart');
Route::post('/cart/lines', [CartController::class, 'add'])->middleware('throttle:60,1,cart')->name('cart.add');
Route::delete('/cart/lines/{solution}', [CartController::class, 'remove'])->whereNumber('solution')->name('cart.remove');
Route::post('/cart/coupons', [CartController::class, 'applyCoupon'])->middleware('throttle:20,1,coupons')->name('cart.coupons.apply');
Route::delete('/cart/coupons/{code}', [CartController::class, 'removeCoupon'])->where('code', '[A-Za-z0-9_-]{1,40}')->name('cart.coupons.remove');

Route::middleware('guest')->group(function () {
    // Tight, because each one sends a message to an address somebody typed.
    Route::post('/checkout/code', [CheckoutController::class, 'sendCode'])->middleware('throttle:6,1,checkout-code')->name('checkout.code');
    Route::post('/checkout/code/verify', [CheckoutController::class, 'verify'])->middleware('throttle:20,1,checkout-verify')->name('checkout.verify');
    Route::post('/checkout/restart', [CheckoutController::class, 'restart'])->name('checkout.restart');
    Route::get('/checkout/sign-in', [CheckoutController::class, 'signIn'])->name('checkout.sign-in');
});

Route::post('/cart/order', [CartController::class, 'order'])
    ->middleware(['auth', 'password.owned', 'throttle:10,1,order'])
    ->name('cart.order');

// A source code download: a signed link that expires, made by the client portal (X16).
Route::get('/downloads/licences/{licence}/versions/{version}', DownloadController::class)
    ->whereNumber(['licence', 'version'])
    ->middleware(['signed', 'throttle:30,1,downloads'])
    ->name('downloads.licence');
