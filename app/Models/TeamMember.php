<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A person shown on the public site: on the About page as part of the team,
 * on the Training page as a mentor, or both.
 *
 * Only real people. There is no seeded team, and both sections render nothing
 * until somebody is added, for the same reason the testimonials section does.
 */
class TeamMember extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'skills' => 'array',
            'on_about' => 'boolean',
            'is_mentor' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** The team on the About page, in the order set in the panel. */
    public function scopeForAbout(Builder $query): Builder
    {
        return $query->active()->where('on_about', true)->orderBy('sort_order')->orderBy('id');
    }

    /** The mentors on the Training page, in the order set in the panel. */
    public function scopeMentors(Builder $query): Builder
    {
        return $query->active()->where('is_mentor', true)->orderBy('sort_order')->orderBy('id');
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }

    /** What a public page gets: nothing it does not show. */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $this->role,
            'bio' => $this->bio,
            'experience' => $this->experience,
            'skills' => array_values($this->skills ?? []),
            'photo' => $this->photoUrl(),
            'linkedin' => $this->linkedin_url,
        ];
    }
}
