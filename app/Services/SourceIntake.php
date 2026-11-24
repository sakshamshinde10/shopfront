<?php

namespace App\Services;

use App\Jobs\ExtractSource;
use App\Models\Fact;
use App\Models\Site;
use App\Models\Source;
use App\Services\Extraction\SocialSites;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** The one place where links, notes and files are added to a site. */
class SourceIntake
{
    public const MAX_SOURCES = 30;

    public function link(Site $site, string $url, ?string $label = null): Source
    {
        // Adding the same link twice would only duplicate its facts.
        if ($existing = $site->sources()->where('kind', Source::LINK)->where('url', $url)->first()) {
            return $existing;
        }
        $this->ensureRoom($site);

        $source = $site->sources()->create([
            'kind' => Source::LINK,
            'url' => $url,
            'label' => $label ?: Str::limit((string) parse_url($url, PHP_URL_HOST), 190, ''),
            'status' => Source::PENDING,
        ]);

        // The owner typed this link in, so it is theirs to vouch for. We record it as a
        // confirmed fact without having to read the page (many social sites cannot be read).
        if (SocialSites::isSocial($url)) {
            $site->facts()->create([
                'source_id' => $source->id,
                'field' => 'social',
                'value' => $url,
                'status' => Fact::CONFIRMED,
            ]);
        }

        ExtractSource::dispatch($source);

        return $source;
    }

    public function note(Site $site, string $text, ?string $label = null): Source
    {
        $this->ensureRoom($site);

        return $site->sources()->create([
            'kind' => Source::NOTE,
            'label' => $label ?: 'Note from the owner',
            'text' => mb_scrub(trim($text)),
            'status' => Source::DONE,
        ]);
    }

    public function file(Site $site, UploadedFile $file): Source
    {
        $this->ensureRoom($site);

        $source = $site->sources()->create([
            'kind' => Source::FILE,
            // Keep only a plain file name: it is shown back to the user.
            'label' => Str::limit(basename($file->getClientOriginalName()), 190, ''),
            // Stored outside the public folder under a random name, so it cannot be guessed or opened by URL.
            'path' => $file->store("sources/{$site->id}", 'local'),
            'mime' => $file->getMimeType(),
            'status' => Source::PENDING,
        ]);

        ExtractSource::dispatch($source);

        return $source;
    }

    public function remove(Source $source): void
    {
        if ($source->path) {
            Storage::disk('local')->delete($source->path);
        }

        // Facts that came only from this source must go with it.
        $source->facts()->delete();
        $source->delete();
    }

    private function ensureRoom(Site $site): void
    {
        if ($site->sources()->count() >= self::MAX_SOURCES) {
            throw ValidationException::withMessages([
                'sources' => 'A site can have at most '.self::MAX_SOURCES.' sources.',
            ]);
        }
    }
}
