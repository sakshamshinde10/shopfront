<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;

/**
 * The finished website, as a visitor sees it. It is plain HTML with no JavaScript. Everything the
 * owner or the AI wrote is escaped when it is printed, and a strict content policy stops anything
 * else from running, so nothing typed into a section can ever harm a visitor.
 */
class PublicPageController extends Controller
{
    public function show(string $slug): Response
    {
        $site = Site::where('slug', $slug)->where('status', Site::PUBLISHED)->with('sections')->firstOrFail();

        $content = $site->sections->pluck('content', 'key');
        $contact = $content->get('contact', []);

        $response = response()->view('public.site', [
            'site' => $site,
            'hero' => $content->get('hero', []),
            'about' => $content->get('about')['paragraphs'] ?? [],
            'services' => $content->get('services', []),
            'contact' => $contact,
            'tel' => isset($contact['phone']) ? preg_replace('/[^0-9+]/', '', $contact['phone']) : null,
            'email' => isset($contact['email']) && filter_var($contact['email'], FILTER_VALIDATE_EMAIL) ? $contact['email'] : null,
            // Only web links are ever turned into clickable links.
            'social' => collect($contact['social'] ?? [])->filter(fn ($url) => preg_match('#^https?://#i', $url))->values(),
            'photos' => $site->photos()->map(fn ($photo) => url("/api/public/{$site->slug}/photos/{$photo->id}")),
            'description' => $this->description($content->get('hero', []), $content->get('about')['paragraphs'] ?? [], $site),
        ]);

        // In development Vite serves styles from another address, which this policy would block.
        if (! Vite::isRunningHot()) {
            $response->header('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
        }

        return $response->withHeaders([
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    private function description(array $hero, array $about, Site $site): string
    {
        $text = $hero['tagline'] ?? ($about[0] ?? null) ?: $site->business_name.($site->city ? " in {$site->city}" : '');

        return Str::limit((string) $text, 160);
    }
}
