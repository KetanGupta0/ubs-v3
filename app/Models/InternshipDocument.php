<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The paperwork an internship produces.
 *
 * Four kinds, at four different moments: the offer letter before it starts, the
 * completion certificate and the mentor evaluation at the end, and the project
 * report assembled from what the student actually submitted along the way
 * rather than written in a panic on the last day.
 *
 * Every one is verifiable, because a college checks.
 */
class InternshipDocument extends Model
{
    public const KINDS = ['offer_letter', 'certificate', 'project_report', 'mentor_evaluation'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (InternshipDocument $document) {
            $document->number ??= self::nextNumber($document->kind);
            $document->verification_code ??= Certificate::newCode();
        });
    }

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'payload' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            'offer_letter' => 'Offer letter',
            'certificate' => 'Completion certificate',
            'project_report' => 'Project report',
            default => 'Mentor evaluation',
        };
    }

    public function verificationUrl(): string
    {
        return url("/verify/{$this->verification_code}");
    }

    public static function nextNumber(string $kind): string
    {
        $prefix = match ($kind) {
            'offer_letter' => 'OL',
            'certificate' => 'IC',
            'project_report' => 'PR',
            default => 'ME',
        };

        $year = now()->year;
        $last = static::query()
            ->where('number', 'like', "UBS/{$prefix}/{$year}/%")
            ->orderByDesc('id')
            ->value('number');

        $sequence = $last ? (int) str($last)->afterLast('/')->toString() : 0;

        return sprintf('UBS/%s/%d/%04d', $prefix, $year, $sequence + 1);
    }
}
