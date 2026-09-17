<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * What a student did, and when.
 *
 * Kept for the student's own streak and for a trainer trying to work out
 * whether somebody has quietly stopped turning up. It is not a surveillance
 * log: it records actions taken in the application, not time on page.
 */
class ActivityLog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'occurred_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function describe(): string
    {
        return match ($this->action) {
            'lesson.completed' => 'Finished “'.($this->meta['title'] ?? 'a lesson').'”',
            'quiz.submitted' => 'Sat “'.($this->meta['title'] ?? 'a quiz').'”'
                .(isset($this->meta['percent']) ? ' and scored '.$this->meta['percent'].'%' : ''),
            'assignment.submitted' => 'Submitted “'.($this->meta['title'] ?? 'an assignment').'”',
            'session.attended' => 'Attended “'.($this->meta['title'] ?? 'a class').'”',
            'material.downloaded' => 'Downloaded “'.($this->meta['title'] ?? 'a file').'”',
            'enrolled' => 'Joined '.($this->meta['title'] ?? 'a course'),
            'certificate.issued' => 'Earned a certificate',
            default => str($this->action)->replace('.', ' ')->ucfirst()->toString(),
        };
    }
}
