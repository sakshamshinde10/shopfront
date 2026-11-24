<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Site extends Model
{
    use HasFactory;

    public const DRAFT = 'draft';
    public const PROCESSING = 'processing';
    public const NEEDS_INPUT = 'needs_input';
    public const READY = 'ready';
    public const PUBLISHED = 'published';

    protected $fillable = ['business_name', 'city', 'status'];

    // The edit token is a secret: never include it in JSON by accident.
    protected $hidden = ['edit_token'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (Site $site) {
            $site->edit_token ??= Str::random(64);
            $site->slug ??= static::uniqueSlug($site->business_name, $site->city);
        });
    }

    /** "Sharma Bakery" in "Pune" becomes sharma-bakery-pune, with a short suffix if taken. */
    public static function uniqueSlug(string $name, ?string $city): string
    {
        $base = Str::slug(trim($name.' '.$city)) ?: 'site';
        $slug = $base;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        return $slug;
    }

    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    public function facts(): HasMany
    {
        return $this->hasMany(Fact::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class)->orderBy('position');
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    public function openQuestions(): HasMany
    {
        return $this->questions()->whereNull('answered_at');
    }
}
