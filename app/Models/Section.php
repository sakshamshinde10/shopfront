<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Section extends Model
{
    // The sections every site has, in the order they appear on the page.
    public const KEYS = ['hero', 'about', 'services', 'contact'];

    protected $fillable = ['site_id', 'key', 'position', 'content', 'edited_by_owner'];

    protected function casts(): array
    {
        return ['content' => 'array', 'edited_by_owner' => 'boolean'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
