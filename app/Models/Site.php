<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Site extends Model
{
    use HasFactory;

    public const DRAFT = 'draft';

    public const PROCESSING = 'processing';

    public const NEEDS_INPUT = 'needs_input';

    public const READY = 'ready';

    public const PUBLISHED = 'published';

    // The AI was unavailable and we could not finish. Sources are kept; the owner can try again.
    public const FAILED = 'failed';

    protected $fillable = ['business_name', 'city', 'status', 'notice', 'published_at'];

    protected $attributes = ['status' => self::DRAFT];

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

    /** Uploaded photos, in the order they were added. Only real image types are ever shown. */
    public function photos(): Collection
    {
        return $this->sources()
            ->where('kind', Source::FILE)
            ->whereIn('mime', Source::PUBLIC_IMAGE_TYPES)
            ->orderBy('id')
            ->get();
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    public function openQuestions(): HasMany
    {
        return $this->questions()->whereNull('answered_at');
    }

    public function hasBlockingQuestions(): bool
    {
        return $this->openQuestions()->whereIn('kind', Question::BLOCKING)->exists();
    }
}
