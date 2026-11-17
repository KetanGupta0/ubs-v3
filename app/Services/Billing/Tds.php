<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\TdsDeduction;
use App\Models\User;
use App\Notifications\TdsNotice;
use App\Services\Admin\Auditor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * TDS on our invoices (CL14).
 *
 * A client that deducts tax at source says so before paying: the section
 * and its rate come from config, the amount is worked out here on the
 * invoice's taxable value, and the client pays the net. The deduction
 * settles the rest of the invoice; the Form 16A they upload later is what
 * proves it, and our side checks it against Form 26AS.
 */
class Tds
{
    public function __construct(
        protected Invoicer $invoicer,
        protected Auditor $auditor,
    ) {}

    /** @return array<string, array{label: string, rate: float|int}> */
    public static function sections(): array
    {
        return config('company.tds.sections', []);
    }

    /** What a section would deduct from an invoice: on the taxable value, never on the GST. */
    public static function amountFor(Invoice $invoice, string $section): int
    {
        $rate = (float) (self::sections()[$section]['rate'] ?? 0);

        return (int) round($invoice->subtotal * $rate / 100);
    }

    public function declare(PaymentRequest $request, User $member, string $section): TdsDeduction
    {
        if (! isset(self::sections()[$section])) {
            throw new InvalidArgumentException('Choose the section you deduct under.');
        }

        if (! $request->user?->isClient() || ! $request->isPayable()) {
            throw new InvalidArgumentException('TDS can be declared on an open invoice to an organisation.');
        }

        $invoice = $this->invoicer->issueFor($request);

        $deduction = DB::transaction(function () use ($invoice, $member, $section) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->tdsDeduction()->exists()) {
                throw new InvalidArgumentException('TDS is already declared on this invoice.');
            }

            if ($locked->amount_paid > 0) {
                throw new InvalidArgumentException('Part of this invoice is already paid. Tell us about the TDS and we will record it.');
            }

            $amount = min(self::amountFor($locked, $section), $locked->balance());
            $quarter = TdsDeduction::quarterOf(now());

            $deduction = $locked->tdsDeduction()->create([
                'client_id' => $locked->user_id,
                'section' => $section,
                'rate' => (float) self::sections()[$section]['rate'],
                'base' => $locked->subtotal,
                'amount' => $amount,
                'quarter' => $quarter['quarter'],
                'certificate_due_on' => $quarter['due'],
                'declared_by' => $member->id,
                'status' => 'declared',
            ]);

            $locked->forceFill(['tds_amount' => $amount])->save();
            $this->invoicer->settle($locked);

            return $deduction;
        });

        $this->auditor->actingAs($member)->action('tds.declared', $invoice, [
            'section' => $section,
            'amount' => $deduction->amount,
        ], $invoice->number);

        return $deduction;
    }

    /** Taken back before anything was paid: a mistaken declaration, not a record to keep. */
    public function withdraw(TdsDeduction $deduction, User $member): void
    {
        DB::transaction(function () use ($deduction) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($deduction->invoice_id);

            if ($deduction->status !== 'declared' || $invoice->amount_paid > 0) {
                throw new InvalidArgumentException('Once something is paid or a certificate is in, the TDS stays on record.');
            }

            $deduction->delete();
            $invoice->forceFill(['tds_amount' => 0])->save();
            $this->invoicer->settle($invoice);
        });

        $this->auditor->actingAs($member)->action('tds.withdrawn', $deduction->invoice, ['amount' => $deduction->amount], $deduction->invoice->number);
    }

    public function uploadCertificate(TdsDeduction $deduction, User $member, UploadedFile $file): void
    {
        if (! in_array($deduction->status, ['declared', 'mismatch'], true)) {
            throw new InvalidArgumentException('We already have the certificate for this.');
        }

        $path = $file->storeAs("tds/{$deduction->client_id}", Str::uuid().'.pdf', 'private');

        $deduction->forceFill([
            'certificate_path' => $path,
            'certificate_uploaded_at' => now(),
            'certificate_uploaded_by' => $member->id,
            'status' => 'received',
            'check_note' => null,
        ])->save();

        $this->auditor->actingAs($member)->action('tds.certificate_received', $deduction->invoice, [], $deduction->invoice->number);
        Notification::send(User::query()->where('is_owner', true)->get(), new TdsNotice($deduction, 'received'));
    }

    /** Our side checks the certificate against Form 26AS. */
    public function check(TdsDeduction $deduction, User $staff, bool $matches, ?string $note): void
    {
        if ($deduction->status !== 'received') {
            throw new InvalidArgumentException('Only a certificate that has come in can be checked.');
        }

        if (! $matches && blank($note)) {
            throw new InvalidArgumentException('Say what does not match, so the client can correct it.');
        }

        $deduction->forceFill([
            'status' => $matches ? 'verified' : 'mismatch',
            'checked_at' => now(),
            'checked_by' => $staff->id,
            'check_note' => filled($note) ? trim($note) : null,
        ])->save();

        $this->auditor->actingAs($staff)->action($matches ? 'tds.verified' : 'tds.mismatch', $deduction->invoice, [], $deduction->invoice->number);

        if (! $matches) {
            Notification::send($deduction->client->clientRecipients('billing'), new TdsNotice($deduction, 'mismatch'));
        }
    }
}
