<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\InternshipDocument;
use App\Support\Seo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Checking a document we issued.
 *
 * Public and unauthenticated. The person checking is an employer or a college
 * office, and making them create an account to verify something we issued would
 * defeat the point of issuing it.
 *
 * The page shows the holder's name, what they completed, and when, because that
 * is what is being checked. It shows nothing else about them: an email address
 * or a phone number is not needed to confirm a certificate, and putting one on
 * a page anybody can reach with a guessable code would be careless.
 */
class VerificationController extends Controller
{
    public function __invoke(Request $request, ?string $code = null): Response
    {
        $raw = trim((string) ($code ?? $request->string('code')));
        $code = strtoupper($raw);

        return Inertia::render('Verify', [
            'code' => $code,
            'result' => $code === '' ? null : $this->look($code, $raw),
            'seo' => Seo::for(
                'Verify a certificate',
                'Check a certificate or internship document issued by Unboundbyte Solutions using the code printed on it.',
                '/verify',
            ),
        ]);
    }

    /** @return array<string, mixed> */
    protected function look(string $code, string $raw): array
    {
        $certificate = Certificate::query()
            ->where('verification_code', $code)
            ->with(['user:id,name', 'course:id,title', 'batch:id,name,starts_on,ends_on'])
            ->first()
            ?? $this->legacy($raw);

        if ($certificate) {
            return [
                'found' => true,
                'valid' => $certificate->isValid(),
                'revokedReason' => $certificate->revoked_reason,
                'kind' => 'Certificate of completion',
                'number' => $certificate->number,
                'holder' => $certificate->user->name,
                'title' => $certificate->title,
                'issuedAt' => $certificate->issued_at->format('j F Y'),
                'grade' => $certificate->grade,
                'percent' => $certificate->final_percent ? (float) $certificate->final_percent : null,
                'period' => $certificate->batch?->starts_on && $certificate->batch?->ends_on
                    ? $certificate->batch->starts_on->format('M Y').' to '.$certificate->batch->ends_on->format('M Y')
                    : null,
            ];
        }

        $document = InternshipDocument::query()
            ->where('verification_code', $code)
            ->with(['user:id,name', 'course:id,title'])
            ->first();

        if ($document) {
            $payload = $document->payload ?? [];

            return [
                'found' => true,
                'valid' => true,
                'kind' => $document->kindLabel(),
                'number' => $document->number,
                'holder' => $document->user->name,
                'title' => $document->course?->title ?? ($payload['title'] ?? 'Internship'),
                'issuedAt' => $document->issued_at->format('j F Y'),
                'college' => $payload['college'] ?? null,
                'period' => isset($payload['startsOn'], $payload['endsOn'])
                    ? Carbon::parse($payload['startsOn'])->format('M Y')
                        .' to '.Carbon::parse($payload['endsOn'])->format('M Y')
                    : null,
            ];
        }

        // Identical for a code that never existed and one that was mistyped.
        // Distinguishing them would hand somebody a way to probe for real ones.
        return ['found' => false];
    }

    /**
     * A certificate the old site issued, found by the token its QR code
     * carries. Compared exactly, case and all, because the token is
     * mixed-case and the database may compare without case.
     */
    protected function legacy(string $raw): ?Certificate
    {
        if (strlen($raw) < 32) {
            return null;
        }

        $certificate = Certificate::query()
            ->where('legacy_token', $raw)
            ->with(['user:id,name', 'course:id,title', 'batch:id,name,starts_on,ends_on'])
            ->first();

        return $certificate && hash_equals((string) $certificate->legacy_token, $raw) ? $certificate : null;
    }
}
