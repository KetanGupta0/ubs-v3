<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Mail\CampaignMail;
use App\Models\MailCampaign;
use App\Models\MailCampaignRecipient;
use App\Models\MailSuppression;
use App\Services\Mail\Suppressions;
use App\Support\Markdown;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A greeting or an announcement, read in the platform (§8), and the switch
 * that stops them. Only somebody it was sent to can open one.
 */
class MessageController extends Controller
{
    public function show(Request $request, int $campaign): Response
    {
        $user = $request->user();
        $record = MailCampaign::query()->with('media')->findOrFail($campaign);
        // Everybody it was put in front of; not somebody on the do-not-mail list, who never saw it.
        abort_unless(MailCampaignRecipient::query()
            ->where('campaign_id', $record->id)
            ->where('user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('skip_reason')->orWhere('skip_reason', '!=', 'suppressed'))
            ->exists(), 404);

        return Inertia::render('Message', [
            'message' => [
                'subject' => CampaignMail::fill($record->subject, $user->name),
                'html' => Markdown::html(CampaignMail::fill($record->message, $user->name)),
                'image' => $record->media?->url(),
                'imageAlt' => $record->media?->alt,
                'date' => ($record->started_at ?? $record->created_at)->format('j F Y'),
            ],
            'receiving' => ! MailSuppression::has($user->email),
        ]);
    }

    /** The account's own switch for greetings and announcements, by mail and here. */
    public function preference(Request $request, Suppressions $suppressions): RedirectResponse
    {
        $validated = $this->validatedInput($request, ['receive' => ['boolean']]);
        $email = $request->user()->email;

        if ($validated['receive']) {
            return $suppressions->allow($email)
                ? back()->with('success', 'You will get our greetings and announcements.')
                : back()->with('error', 'Mail to your address was stopped because it could not be delivered. Write to us and we will look at it.');
        }

        $suppressions->suppress($email, 'unsubscribed');

        return back()->with('success', 'No more greetings or announcements. Messages about your account still come.');
    }
}
