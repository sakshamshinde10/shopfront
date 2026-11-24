<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Question extends Model
{
    public const PICK_BUSINESS = 'pick_business';

    public const CONFLICT = 'conflict';

    public const MISSING = 'missing';

    protected $fillable = ['site_id', 'source_id', 'kind', 'field', 'prompt', 'options', 'answer', 'answered_at'];

    // These stop the site from being written until the owner answers. "missing" ones are optional.
    public const BLOCKING = [self::PICK_BUSINESS, self::CONFLICT];

    protected function casts(): array
    {
        return ['options' => 'array', 'answered_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function isOpen(): bool
    {
        return $this->answered_at === null;
    }
}
