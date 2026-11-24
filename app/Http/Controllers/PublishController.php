<?php

namespace App\Http\Controllers;

use App\Http\Resources\SiteResource;
use App\Models\Site;
use Illuminate\Http\JsonResponse;

class PublishController extends Controller
{
    public function publish(Site $site): SiteResource|JsonResponse
    {
        if ($site->isPublished()) {
            return new SiteResource($site);
        }

        if ($site->status !== Site::READY) {
            return response()->json([
                'message' => 'Finish building the site (and answer any open questions) before publishing it.',
            ], 422);
        }
        if (! $site->sections()->where('key', 'hero')->exists()) {
            return response()->json(['message' => 'The site has no content yet.'], 422);
        }

        $site->update(['status' => Site::PUBLISHED, 'published_at' => now()]);

        return new SiteResource($site);
    }

    /** Take the site offline again. The owner's work is kept. */
    public function unpublish(Site $site): SiteResource
    {
        if ($site->isPublished()) {
            $site->update(['status' => Site::READY, 'published_at' => null]);
        }

        return new SiteResource($site);
    }
}
