<?php

namespace App\Http\Resources;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the world is allowed to see of a published site. It lists only the finished sections
 * and the photos: never sources, questions, facts or anything about how it was made.
 *
 * @mixin Site
 */
class PublicSiteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'business_name' => $this->business_name,
            'city' => $this->city,
            'published_at' => $this->published_at,
            'sections' => $this->sections->map(fn ($section) => ['key' => $section->key, 'content' => $section->content])->values(),
            'photos' => $this->photos()->map(fn ($photo) => url("/api/public/{$this->slug}/photos/{$photo->id}")),
        ];
    }
}
