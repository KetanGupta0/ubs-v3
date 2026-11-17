<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One signature on one document (CL2). Never changed and never deleted: it is
 * the evidence that a named person, proved by a one time code, agreed to the
 * exact bytes whose hash it holds.
 */
class DocumentSignature extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A signature is never changed.'));
        static::deleting(fn () => throw new LogicException('A signature is never deleted.'));
    }

    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }

    public function signable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, mixed> */
    public function toCertificateArray(): array
    {
        return [
            'party' => $this->party === 'us' ? 'Countersigned by' : 'Signed by',
            'side' => $this->party,
            'name' => $this->signer_name,
            'designation' => $this->signer_designation,
            'organisation' => $this->organisation,
            'email' => $this->signer_email,
            'signedAt' => $this->signed_at->format('j M Y, g:i:s a T'),
            'verified' => "One time code sent to {$this->otp_destination}",
            'ip' => $this->ip,
            'userAgent' => $this->user_agent,
            'sha256' => $this->document_sha256,
        ];
    }
}
