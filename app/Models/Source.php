<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    public const LINK = 'link';

    public const FILE = 'file';

    public const NOTE = 'note';

    public const PENDING = 'pending';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const BLOCKED = 'blocked';

    // What a web search found: the list of matching places, or the website of one of them.
    public const SEARCH = 'search';

    protected $fillable = [
        'site_id', 'kind', 'label', 'url', 'path', 'mime', 'status', 'text', 'error', 'warning', 'identity', 'analysed_at',
    ];

    // Raw extracted text can be large and is not needed by the browser.
    protected $hidden = ['text', 'path'];

    protected function casts(): array
    {
        return ['identity' => 'array', 'analysed_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function facts(): HasMany
    {
        return $this->hasMany(Fact::class);
    }
}
