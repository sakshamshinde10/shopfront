<?php

namespace App\Http\Resources;

use App\Models\Site;
use App\Models\Source;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Site */
class SiteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'business_name' => $this->business_name,
            'city' => $this->city,
            'status' => $this->status,
            'published_at' => $this->published_at,
            'sources' => SourceResource::collection($this->whenLoaded('sources')),
            // Reading links and files happens in the background; the page can poll until this is 0.
            'pending_sources' => $this->whenLoaded(
                'sources',
                fn () => $this->sources->where('status', Source::PENDING)->count(),
            ),
        ];
    }
}
