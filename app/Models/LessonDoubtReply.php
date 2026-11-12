<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonDoubtReply extends Model
{
    protected $fillable = ['lesson_doubt_id', 'user_id', 'body', 'from_staff'];

    protected function casts(): array
    {
        return ['from_staff' => 'boolean'];
    }

    public function doubt(): BelongsTo
    {
        return $this->belongsTo(LessonDoubt::class, 'lesson_doubt_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
