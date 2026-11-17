<?php

namespace App\Services\Client;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Enums\OtpResult;
use App\Models\Agreement;
use App\Models\ChangeRequest;
use App\Models\DocumentSignature;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\DocumentCountersigned;
use App\Notifications\DocumentSigned;
use App\Services\Admin\Auditor;
use App\Services\Auth\Impersonation;
use App\Services\Auth\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * The built-in electronic signature (CL2, decision X2).
 *
 * To sign, a person reads the PDF, types their name and enters a one time
 * code sent to their own registered address. What is recorded is the
 * SHA-256 of the exact PDF that was sent, who signed, when, from where and
 * how they proved it; a signature is never changed. A client's signatory
 * signs (or accepts a proposal by signing it), and for an agreement somebody
 * on our side countersigns the same way.
 */
class Signatures
{
    public function __construct(
        protected OtpService $otp,
        protected ClientDocuments $documents,
        protected Auditor $auditor,
    ) {}

    /** The first time somebody in the client's organisation opens it. Staff looking do not count. */
    public function markViewed(Agreement|Proposal|ChangeRequest $document, User $viewer): void
    {
        if ($document->viewed_at || ! $viewer->isClient() || app(Impersonation::class)->active()) {
            return;
        }

        $document->forceFill(['viewed_at' => now(), 'viewed_by' => $viewer->id])->save();
    }

    /**
     * Send a code to the signer's own email address.
     *
     * @return string Where it went, partly hidden, for the screen to say.
     */
    public function requestCode(User $signer, Agreement|Proposal|ChangeRequest $document, string $party, Request $request): string
    {
        $this->mustBeSignable($document, $party);

        if ($this->otp->hasReachedHourlyLimit($signer->email, OtpPurpose::Sign)) {
            throw new InvalidArgumentException('Too many codes this hour. Try again later.');
        }

        if (($wait = $this->otp->cooldownFor($signer->email, OtpPurpose::Sign)) > 0) {
            throw new InvalidArgumentException("A code was sent a moment ago. Wait {$wait} seconds before asking for another.");
        }

        $this->otp->issue($signer, $signer->email, OtpChannel::Email, OtpPurpose::Sign, $request->ip());

        return $this->mask($signer->email);
    }

    /** Check the code and record the signature, then move the document on. */
    public function sign(
        User $signer,
        Agreement|Proposal|ChangeRequest $document,
        string $party,
        string $typedName,
        ?string $designation,
        string $code,
        Request $request,
        ?string $note = null,
    ): DocumentSignature {
        $this->mustBeSignable($document, $party);

        $result = $this->otp->verify($signer->email, OtpPurpose::Sign, $code);

        if (! $result->passed()) {
            throw new InvalidArgumentException(match ($result) {
                OtpResult::Expired => 'That code has expired. Ask for a new one.',
                OtpResult::TooManyAttempts => 'Too many wrong codes. Ask for a new one.',
                OtpResult::AlreadyUsed, OtpResult::NotFound => 'Ask for a code first.',
                default => 'That code is not right. Check it and try again.',
            });
        }

        // The file still has to be the one that was sent. It always should
        // be; if it is not, nobody signs anything until somebody looks.
        $bytes = Storage::disk('private')->get((string) $document->pdf_path);

        if (! $bytes || hash('sha256', $bytes) !== $document->pdf_sha256) {
            report(new \RuntimeException("Stored PDF of {$document->number} does not match its recorded hash."));

            throw new InvalidArgumentException('This document cannot be signed right now. We have been told and will look at it.');
        }

        $signature = DB::transaction(function () use ($signer, &$document, $party, $typedName, $designation, $request, $note) {
            // Two signatories pressing Sign together must not both succeed.
            $document = $document->newQuery()->lockForUpdate()->findOrFail($document->getKey());
            $this->mustBeSignable($document, $party);

            $signature = $document->signatures()->create([
                'party' => $party,
                'user_id' => $signer->id,
                'signer_name' => trim($typedName),
                'signer_designation' => filled($designation) ? trim($designation) : null,
                'signer_email' => $signer->email,
                'organisation' => $party === 'client' ? $signer->organisationName() : config('company.legal_name', config('app.name')),
                'document_sha256' => $document->pdf_sha256,
                'otp_channel' => OtpChannel::Email->value,
                'otp_destination' => $this->mask($signer->email),
                'ip' => $request->ip(),
                'user_agent' => str((string) $request->userAgent())->limit(490)->toString(),
                'signed_at' => now(),
            ]);

            if ($document instanceof ChangeRequest) {
                // Approving the addendum changes the project (CL12).
                app(ChangeRequests::class)->applyApproval($document, $signer);
            } elseif ($document instanceof Proposal) {
                $document->forceFill([
                    'status' => 'accepted',
                    'responded_at' => now(),
                    'responded_ip' => $request->ip(),
                    'response_note' => filled($note) ? trim($note) : null,
                ])->save();
                $document->quotation?->forceFill(['status' => 'accepted'])->save();
            } else {
                $document->forceFill(['status' => $party === 'client' ? 'signed' : 'countersigned'])->save();
            }

            return $signature;
        });

        $this->documents->signedCopy($document->fresh());

        $this->auditor->actingAs($signer)->action(
            match (true) {
                $document instanceof Proposal => 'proposal.accepted',
                $document instanceof ChangeRequest => 'change_request.approved',
                default => $party === 'client' ? 'agreement.signed' : 'agreement.countersigned',
            },
            $document,
            ['signature' => $signature->id, 'sha256' => $document->pdf_sha256, 'by' => $party],
            $document->number,
        );

        $this->tell($document->fresh(), $party);

        return $signature;
    }

    /* -------------------------------------------------------------------- */

    protected function mustBeSignable(Agreement|Proposal|ChangeRequest $document, string $party): void
    {
        $problem = match (true) {
            ! $document->pdf_path || ! $document->pdf_sha256 => 'This document has no PDF to sign yet.',
            ! $document instanceof Agreement && $party !== 'client' => 'Only an agreement is countersigned.',
            $document instanceof ChangeRequest && ! $document->canBeAnswered() => 'This change request is not waiting for approval.',
            $document instanceof Proposal && ! $document->canBeAnswered() => $document->hasExpired()
                ? 'This proposal has passed its validity date. Ask us for a fresh one.'
                : 'This proposal has already been answered.',
            $document instanceof Agreement && $party === 'client' && $document->status !== 'sent' => 'This agreement is not waiting for your signature.',
            $document instanceof Agreement && $party === 'us' && $document->status !== 'signed' => 'Only an agreement the client has signed can be countersigned.',
            default => null,
        };

        if ($problem) {
            throw new InvalidArgumentException($problem);
        }
    }

    /** The other side hears about it. */
    protected function tell(Agreement|Proposal|ChangeRequest $document, string $party): void
    {
        if ($party === 'client') {
            $author = $document instanceof ChangeRequest ? $document->quoted_by : $document->created_by;
            $staff = User::query()
                ->where(fn ($query) => $query->where('is_owner', true)->when($author, fn ($inner) => $inner->orWhere('id', $author)))
                ->get();

            Notification::send($staff, new DocumentSigned($document));

            return;
        }

        Notification::send($document->client->clientRecipients('signing'), new DocumentCountersigned($document));
    }

    protected function mask(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 2).str_repeat('•', max(1, mb_strlen($name) - 2)).'@'.$domain;
    }
}
