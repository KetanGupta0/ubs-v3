<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something that happened during a quiz attempt (§9.1, layer 2), at the
 * server's time.
 *
 * Some are written by the server (started, an answer saved, submitted, a new
 * network or device); others are reported by the browser (left the quiz,
 * pasted, copied), which can miss them or fake them. They are evidence a
 * person weighs, never proof, and the review screen says so.
 */
class QuizAttemptEvent extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    /** What the browser may report. Everything else is the server's to write. */
    public const FROM_BROWSER = ['focus_lost', 'focus_back', 'fullscreen_exit', 'fullscreen_enter', 'paste', 'copy', 'blocked', 'message_seen'];

    /** What a proctored page blocks, and so may report trying (§9.1a). */
    public const BLOCKABLE = ['copy', 'cut', 'paste', 'menu', 'drag', 'shortcut', 'print'];

    /** Why the questions were covered: the tab, the window, or full screen. */
    public const LEFT_BY = ['tab', 'window', 'fullscreen'];

    public const LABELS = [
        'started' => 'Started',
        'resumed' => 'Opened again',
        'answered' => 'Answered',
        'submitted' => 'Submitted',
        'auto_submitted' => 'Submitted when the time ran out',
        'focus_lost' => 'Left the quiz',
        'focus_back' => 'Came back to the quiz',
        'fullscreen_exit' => 'Left full screen',
        'fullscreen_enter' => 'Back in full screen',
        'paste' => 'Pasted into an answer',
        'copy' => 'Copied from the question',
        'ip_changed' => 'Network changed',
        'device_changed' => 'Carried on in another browser',
        'consented' => 'Agreed to the proctoring rules',
        'blocked' => 'Tried something switched off',
        'proctor_message' => 'Message from the proctor',
        'message_seen' => 'Read the proctor\'s message',
        'time_added' => 'Given more time',
        'ended_by_proctor' => 'Ended by the proctor',
        'ended_for_exits' => 'Submitted for leaving too often',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'occurred_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class, 'quiz_attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function label(): string
    {
        return self::LABELS[$this->type] ?? ucfirst(str_replace('_', ' ', $this->type));
    }
}
