<?php

namespace App\Services\Client;

use App\Models\Agreement;
use App\Models\ChangeRequest;
use App\Models\Project;
use App\Models\Proposal;
use App\Services\Billing\Invoicer;
use App\Support\Markdown;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * The PDF of every client document (CL3), from one place.
 *
 * A proposal or an agreement is rendered once, when it is sent: the parties
 * are copied onto the row, the file is stored with its SHA-256, and every
 * later download is that file. An invoice already works this way, and for
 * the same reason: what the client was sent is what anybody reads later, and
 * a signature (CL2) is a signature of those exact bytes. A draft can be
 * previewed, and the preview is never kept.
 */
class ClientDocuments
{
    public function __construct(protected Invoicer $invoicer) {}

    /** Freeze a proposal as it goes out: parties, PDF and hash. */
    public function freezeProposal(Proposal $proposal): void
    {
        if ($proposal->pdf_path) {
            return;
        }

        $proposal->forceFill(['parties' => $this->parties($proposal->client)])->save();
        $output = $this->renderProposal($proposal);

        $path = 'documents/proposals/'.str($proposal->number)->replace('/', '-')->toString().'.pdf';
        Storage::disk('private')->put($path, $output);

        $proposal->forceFill(['pdf_path' => $path, 'pdf_sha256' => hash('sha256', $output)])->save();
    }

    /** Freeze an agreement as it goes out. Its text cannot change after this. */
    public function freezeAgreement(Agreement $agreement): void
    {
        if ($agreement->pdf_path) {
            return;
        }

        if ($agreement->status !== 'draft') {
            throw new LogicException('Only a draft is frozen, on its way out.');
        }

        $agreement->forceFill(['parties' => $this->parties($agreement->client)]);
        $output = $this->renderAgreement($agreement);

        $path = 'documents/agreements/'.str($agreement->number)->replace('/', '-')->toString().'.pdf';
        Storage::disk('private')->put($path, $output);

        $agreement->forceFill(['pdf_path' => $path, 'pdf_sha256' => hash('sha256', $output)])->save();
    }

    /** Freeze a change request as it is quoted (CL12): parties, PDF and hash. */
    public function freezeChangeRequest(ChangeRequest $change): void
    {
        if ($change->pdf_path) {
            return;
        }

        $change->forceFill(['parties' => $this->parties($change->client)]);
        $output = $this->renderChangeRequest($change);

        $path = 'documents/change-requests/'.str($change->number)->replace('/', '-')->toString().'.pdf';
        Storage::disk('private')->put($path, $output);

        $change->forceFill(['pdf_path' => $path, 'pdf_sha256' => hash('sha256', $output)]);
    }

    /** The stored file of a sent document. Re-rendering a sent document is never right. */
    public function stored(Proposal|Agreement|ChangeRequest $document): string
    {
        if (! $document->pdf_path || ! Storage::disk('private')->exists($document->pdf_path)) {
            throw new LogicException("{$document->number} has no stored PDF.");
        }

        return Storage::disk('private')->get($document->pdf_path);
    }

    /**
     * The signed copy: the document as sent, with a certificate of signing
     * appended. Made again after every signature; the original stays the
     * file that was signed, and the certificate names it by its hash.
     */
    public function signedCopy(Proposal|Agreement|ChangeRequest $document): string
    {
        $signatures = $document->signatures()->get()->map->toCertificateArray()->all();

        $output = match (true) {
            $document instanceof Proposal => $this->renderProposal($document, $signatures),
            $document instanceof ChangeRequest => $this->renderChangeRequest($document, $signatures),
            default => $this->renderAgreement($document, $signatures),
        };

        $folder = match (true) {
            $document instanceof Proposal => 'proposals',
            $document instanceof ChangeRequest => 'change-requests',
            default => 'agreements',
        };
        $path = "documents/signed/{$folder}/".str($document->number)->replace('/', '-')->toString().'-signed.pdf';
        Storage::disk('private')->put($path, $output);

        $document->forceFill(['signed_pdf_path' => $path])->save();

        return $path;
    }

    /** A draft as it would look if sent now. Not kept. */
    public function preview(Proposal|Agreement|ChangeRequest $document): string
    {
        return match (true) {
            $document instanceof Proposal => $this->renderProposal($document),
            $document instanceof ChangeRequest => $this->renderChangeRequest($document),
            default => $this->renderAgreement($document),
        };
    }

    /** Where a project stands, rendered now. A report, not a document anybody signs. */
    public function statusReport(Project $project): string
    {
        $project->loadMissing(['milestones', 'client.profile']);

        $waiting = app(WaitingItems::class);
        $waitingTime = $waiting->waitingTime($project);

        return Pdf::loadView('documents.status-report', [
            'project' => $project,
            'parties' => $this->parties($project->client),
            'updates' => $project->updates()->where('visible_to_client', true)->latest()->limit(5)->get(),
            'tickets' => $project->tickets()->whereNotIn('status', ['resolved', 'closed'])->latest()->limit(10)->get(),
            // What the project is waiting on the client for, and for how long in all (CL10).
            'waiting' => $project->waitingItems()->where('status', 'open')->get(),
            'waitingTime' => $waitingTime['seconds'] > 0 ? $waitingTime['label'] : null,
        ])->setPaper('a4')->output();
    }

    /* -------------------------------------------------------------------- */

    protected function renderProposal(Proposal $proposal, array $signatures = []): string
    {
        $proposal->loadMissing(['quotation.items', 'client.profile']);
        $quotation = $proposal->quotation;

        return Pdf::loadView('documents.proposal', [
            'proposal' => $proposal,
            'quotation' => $quotation,
            'parties' => $proposal->parties ?? $this->parties($proposal->client),
            'money' => fn (int $paise) => Money::display($paise),
            'words' => $quotation ? Money::words($quotation->total) : null,
            'signatures' => $signatures,
        ])->setPaper('a4')->output();
    }

    protected function renderChangeRequest(ChangeRequest $change, array $signatures = []): string
    {
        $change->loadMissing(['project', 'client.profile']);
        $rate = $this->invoicer->defaultTaxRate();

        return Pdf::loadView('documents.change-request', [
            'change' => $change,
            'parties' => $change->parties ?? $this->parties($change->client),
            'money' => fn (int $paise) => Money::display($paise),
            'taxRate' => $rate,
            'tax' => Money::taxOn((int) ($change->amount ?? 0), $rate),
            'signatures' => $signatures,
        ])->setPaper('a4')->output();
    }

    protected function renderAgreement(Agreement $agreement, array $signatures = []): string
    {
        $agreement->loadMissing(['project', 'client.profile']);

        return Pdf::loadView('documents.agreement', [
            'agreement' => $agreement,
            'parties' => $agreement->parties ?? $this->parties($agreement->client),
            'html' => Markdown::html($agreement->body),
            'signatures' => $signatures,
        ])->setPaper('a4')->output();
    }

    /** @return array{from: array<string, mixed>, to: array<string, mixed>} */
    protected function parties($client): array
    {
        return [
            'from' => $this->invoicer->billedFrom(),
            'to' => $this->invoicer->billedTo($client->clientAccount()),
        ];
    }
}
