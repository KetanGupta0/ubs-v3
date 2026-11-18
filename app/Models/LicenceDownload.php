<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One download of one version under one licence, kept for the count and the record. */
class LicenceDownload extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function licence(): BelongsTo
    {
        return $this->belongsTo(Licence::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ProductVersion::class, 'product_version_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
