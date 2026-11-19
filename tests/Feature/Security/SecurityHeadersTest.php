<?php

use App\Http\Controllers\CspReportController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
 * Step I: the headers every response carries, the content security policy on
 * every page, and what the browser reports when the policy blocks something.
 */

it('sends a page with the security headers and a policy whose nonce is on the inline scripts', function () {
    $response = $this->get('/')->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(self), geolocation=(), usb=()')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin-allow-popups')
        ->assertHeaderMissing('Strict-Transport-Security');

    $policy = $response->headers->get('Content-Security-Policy');
    preg_match("/'nonce-([A-Za-z0-9]+)'/", $policy, $nonce);

    expect($policy)
        ->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'self'")
        ->toContain('https://*.razorpay.com')
        ->toContain('https://challenges.cloudflare.com')
        ->toContain('https://www.youtube.com')
        ->toContain('report-uri /csp-reports')
        ->not->toContain("'unsafe-eval'")
        ->and($nonce[1] ?? null)->not->toBeNull();

    // Every script in the page is either the bundle or carries the nonce.
    preg_match_all('/<script(?![^>]*type="application\/json")[^>]*>/', $response->getContent(), $scripts);
    expect($scripts[0])->not->toBeEmpty();
    foreach ($scripts[0] as $tag) {
        expect($tag)->toContain('nonce="'.$nonce[1].'"');
    }

    // A fresh nonce for every page.
    expect($this->get('/')->headers->get('Content-Security-Policy'))->not->toBe($policy);
});

it('puts the policy on pages only, can report without blocking, and remembers HTTPS in production', function () {
    $this->getJson('/health')->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderMissing('Content-Security-Policy');

    config(['company.security.csp_report_only' => true]);
    $this->get('/')->assertHeaderMissing('Content-Security-Policy')
        ->assertHeader('Content-Security-Policy-Report-Only');

    config(['company.security.csp' => false]);
    $this->get('/')->assertHeaderMissing('Content-Security-Policy')->assertHeaderMissing('Content-Security-Policy-Report-Only');

    $this->app['env'] = 'production';
    config(['company.security.csp' => true, 'company.security.csp_report_only' => false]);
    $response = $this->get('https://localhost/');
    $this->app['env'] = 'testing';

    $response->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    expect($response->headers->get('Content-Security-Policy'))->toEndWith('upgrade-insecure-requests');
});

it('takes what the policy blocked from the browser without a session, keeping only where and what', function () {
    Log::spy();

    $report = fn (array $body) => $this->call('POST', '/csp-reports', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode(['csp-report' => $body]));

    $report([
        'document-uri' => 'https://unboundbyte.test/student/payments/12?ref=meera@example.com',
        'violated-directive' => 'frame-src',
        'effective-directive' => 'frame-src',
        'blocked-uri' => 'https://api.razorpay.com/v1/checkout/public?key_id=rzp_live_123&session=abc',
        'source-file' => 'https://checkout.razorpay.com/v1/checkout.js',
        'disposition' => 'enforce',
    ])->assertNoContent();

    Log::shouldHaveReceived('warning')->once()->with(
        'Content security policy blocked https://api.razorpay.com under frame-src',
        ['directive' => 'frame-src', 'blocked' => 'https://api.razorpay.com', 'page' => '/student/payments/12', 'source' => 'https://checkout.razorpay.com', 'disposition' => 'enforce'],
    );

    // An extension's doing, and nonsense, are dropped.
    $report(['document-uri' => 'https://unboundbyte.test/', 'violated-directive' => 'script-src', 'blocked-uri' => 'chrome-extension://abcdef/inject.js'])->assertNoContent();
    $this->call('POST', '/csp-reports', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], 'not json')->assertNoContent();
    Log::shouldHaveReceived('warning')->once();

    Cache::put('csp-reports:'.today()->toDateString(), CspReportController::DAILY_LIMIT, now()->endOfDay());
    $report(['document-uri' => 'https://unboundbyte.test/', 'violated-directive' => 'img-src', 'blocked-uri' => 'http://example.com/a.png'])->assertNoContent();
    Log::shouldHaveReceived('warning')->once();
});

it('counts an unnamed throttle for its own route, and a named one across the routes that share it', function () {
    Route::middleware('throttle:2,1')->get('/_throttle/a', fn () => 'a');
    Route::middleware('throttle:2,1')->get('/_throttle/b', fn () => 'b');
    Route::middleware('throttle:2,1,shared')->get('/_throttle/c', fn () => 'c');
    Route::middleware('throttle:2,1,shared')->get('/_throttle/d', fn () => 'd');

    $this->get('/_throttle/a')->assertOk();
    $this->get('/_throttle/a')->assertOk();
    $this->get('/_throttle/a')->assertTooManyRequests();
    $this->get('/_throttle/b')->assertOk();

    $this->get('/_throttle/c')->assertOk();
    $this->get('/_throttle/d')->assertOk();
    $this->get('/_throttle/c')->assertTooManyRequests();
});
