<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SolutionCategory extends Model
{
    protected $guarded = ['id'];

    public function solutions(): HasMany
    {
        return $this->hasMany(Solution::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
