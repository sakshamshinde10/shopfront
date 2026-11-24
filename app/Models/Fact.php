<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fact extends Model
{
    public const CANDIDATE = 'candidate';

    public const CONFIRMED = 'confirmed';

    public const REJECTED = 'rejected';

    // What we try to find out about a business.
    public const FIELDS = ['phone', 'email', 'address', 'hours', 'about', 'service', 'price', 'social'];

    // Fields that can only have one true value. Two different values means a conflict.
    public const SINGLE_VALUE = ['phone', 'email', 'address', 'hours'];

    protected $fillable = ['site_id', 'source_id', 'field', 'business', 'value', 'status'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
