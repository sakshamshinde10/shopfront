<?php

namespace App\Http\Controllers;

use App\Http\Resources\PublicSiteResource;
use App\Models\Site;
use App\Models\Source;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The public side: no token needed, but only for sites the owner has published. */
class PublicSiteController extends Controller
{
    public function show(string $slug): PublicSiteResource
    {
        return new PublicSiteResource($this->published($slug)->load('sections'));
    }

    /** A photo from a published site. Files are private on disk, so this is the only way to see one. */
    public function photo(string $slug, int $photo): StreamedResponse
    {
        $site = $this->published($slug);

        $source = $site->sources()
            ->where('kind', Source::FILE)
            ->whereIn('mime', Source::PUBLIC_IMAGE_TYPES)
            ->whereKey($photo)
            ->firstOrFail();

        $disk = Storage::disk('local');
        abort_unless($source->path && $disk->exists($source->path), 404);

        return $disk->response($source->path, null, [
            'Content-Type' => $source->mime,
            // Stops a browser from guessing a different type and running a file as something else.
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function published(string $slug): Site
    {
        return Site::where('slug', $slug)->where('status', Site::PUBLISHED)->firstOrFail();
    }
}
