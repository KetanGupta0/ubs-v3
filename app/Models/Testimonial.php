<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A quote from a real client or student.
 *
 * Seeded empty on purpose. There are no clients to quote yet, and inventing
 * one would be a fabricated endorsement on a real company's website. The
 * section simply does not render until there is something true to put in it.
 */
class Testimonial extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }
}
