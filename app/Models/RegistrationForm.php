<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A registration form for one course, filled without signing in.
 *
 * The form decides when it is open, how many places it has, whether the
 * course fee applies to people who come in through it, and whether a
 * registration enrols at once or waits for somebody to approve it. It does not
 * decide anything about accounts: that is `Registrar`, so the rule about
 * never confirming whether an address is registered lives in one place.
 */
class RegistrationForm extends Model
{
    /** Questions every form can ask beyond name, email and mobile, with their wording. */
    public const STANDARD_FIELDS = [
        'college' => 'College or institution',
        'course_of_study' => 'Course of study',
        'graduation_year' => 'Year of graduation',
        'city' => 'City',
    ];

    public const QUESTION_TYPES = [
        'short' => 'Short answer',
        'long' => 'Paragraph',
        'choice' => 'One of a list',
        'yesno' => 'Yes or no',
    ];

    public const MAX_QUESTIONS = 15;

    protected $fillable = [
        'slug', 'title', 'intro', 'course_id', 'batch_id', 'opens_at', 'closes_at',
        'capacity', 'fee', 'approval', 'fields', 'questions', 'is_active',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'capacity' => 'integer',
            'fields' => 'array',
            'questions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function path(): string
    {
        return "/join/{$this->slug}";
    }

    public function url(): string
    {
        return url($this->path());
    }

    /** Places taken: everybody who registered and has not been turned away or said it was not them. */
    public function placesTaken(): int
    {
        return $this->registrations()->whereNotIn('status', Registration::CLOSED)->count();
    }

    public function placesLeft(): ?int
    {
        return $this->capacity ? max($this->capacity - $this->placesTaken(), 0) : null;
    }

    /**
     * Where the form stands right now: open, not_yet, closed or full.
     *
     * A course taken off the site closes its forms too, because Enroller would
     * put somebody on a course nobody can see.
     */
    public function state(): string
    {
        return match (true) {
            ! $this->is_active, ! $this->course?->is_published => 'closed',
            $this->opens_at && $this->opens_at->isFuture() => 'not_yet',
            $this->closes_at && $this->closes_at->isPast() => 'closed',
            $this->placesLeft() === 0 => 'full',
            default => 'open',
        };
    }

    public function isOpen(): bool
    {
        return $this->state() === 'open';
    }

    /** What the fee will be for somebody coming in through this form, in paise. */
    public function feeInPaise(): int
    {
        return $this->fee === 'free' ? 0 : $this->course->effectivePrice();
    }

    public function feeLabel(): string
    {
        $fee = $this->feeInPaise();

        return $fee === 0 ? 'Free' : Money::display($fee).', inclusive of tax';
    }

    /**
     * The optional standard fields this form asks for, in a fixed order.
     *
     * @return array<string, string>
     */
    public function standardFields(): array
    {
        return array_intersect_key(self::STANDARD_FIELDS, array_flip($this->fields ?? []));
    }

    /** @return array<int, array{key: string, label: string, type: string, required: bool, options: array<int, string>}> */
    public function questionList(): array
    {
        return array_values(array_filter($this->questions ?? [], 'is_array'));
    }
}
