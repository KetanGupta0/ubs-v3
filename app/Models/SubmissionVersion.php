<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An earlier version of a submission, as it stood when the student replaced it. */
class SubmissionVersion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'files' => 'array',
            'submitted_at' => 'datetime',
            'evaluated_at' => 'datetime',
            'is_late' => 'boolean',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by');
    }

    /** @return array<string, mixed> */
    public function toHistoryArray(): array
    {
        return [
            'version' => $this->version,
            'notes' => $this->notes,
            'repositoryUrl' => $this->repository_url,
            'demoUrl' => $this->demo_url,
            'files' => collect($this->files ?? [])->map(fn (array $file, int $index) => [
                'index' => $index,
                'name' => $file['name'] ?? 'Attachment',
                'size' => $file['size'] ?? null,
            ])->values(),
            'submittedAt' => $this->submitted_at?->format('j M Y, g:i a'),
            'late' => $this->is_late,
            'status' => $this->status,
            'marks' => $this->marks,
            'feedback' => $this->feedback,
            'evaluatedBy' => $this->evaluator?->name,
            'evaluatedAt' => $this->evaluated_at?->format('j M Y'),
        ];
    }
}
