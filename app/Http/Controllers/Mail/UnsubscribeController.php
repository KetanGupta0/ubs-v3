<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Models\MailSuppression;
use App\Services\Mail\Suppressions;
use App\Support\Identifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stopping greetings and announcements, from the link in one of them (§8).
 *
 * The link is signed, so it stops mail only to the address it was made for,
 * and never expires. Opening it shows one button rather than unsubscribing at
 * once, because mail scanners open links; a mail app's own unsubscribe button
 * posts straight here (RFC 8058) and is honoured without a page.
 */
class UnsubscribeController extends Controller
{
    public function show(Request $request, string $address): Response
    {
        $email = $this->address($address);
        $suppression = MailSuppression::query()->where('email', MailSuppression::normalise($email))->first();

        return Inertia::render('marketing/Unsubscribe', [
            'email' => Identifier::mask($email),
            'stopped' => $suppression !== null,
            'canResume' => $suppression === null || $suppression->reason === 'unsubscribed',
            'stopUrl' => $request->fullUrl(),
            'resumeUrl' => Suppressions::resubscribeUrl($address),
            'seo' => ['title' => 'Email from us', 'description' => 'Stop greetings and announcements by email.', 'canonical' => null, 'noindex' => true],
        ]);
    }

    public function store(Request $request, string $address, Suppressions $suppressions): RedirectResponse|HttpResponse
    {
        $suppressions->suppress($this->address($address), 'unsubscribed', $request->integer('c') ?: null);

        // A mail app's one-click button wants an answer, not a page.
        if (! $request->header('X-Inertia')) {
            return response('Unsubscribed. You will get no more greetings or announcements from us.', 200, ['Content-Type' => 'text/plain']);
        }

        return back()->with('success', 'Done. You will get no more greetings or announcements from us.');
    }

    public function resume(string $address, Suppressions $suppressions): RedirectResponse
    {
        return $suppressions->allow($this->address($address))
            ? back()->with('success', 'You will get our greetings and announcements again.')
            : back()->with('error', 'Mail to this address was stopped because it could not be delivered. Write to us and we will look at it.');
    }

    protected function address(string $encoded): string
    {
        $email = Suppressions::addressFrom($encoded);
        abort_if($email === null, 404);

        return $email;
    }
}
