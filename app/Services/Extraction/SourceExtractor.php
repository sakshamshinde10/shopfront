<?php

namespace App\Services\Extraction;

use App\Models\Source;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Reads one source and stores what it found. It never throws for expected
 * problems: a blocked site, a bad PDF or a timeout just marks the source so the
 * owner can be asked to paste the details instead.
 */
class SourceExtractor
{
    private const SOCIAL_MIN_TEXT = 150;

    public function __construct(
        private readonly SafeFetcher $fetcher,
        private readonly HtmlReader $html,
        private readonly PdfReader $pdf,
    ) {}

    public function run(Source $source): void
    {
        try {
            match ($source->kind) {
                Source::LINK => $this->link($source),
                Source::SEARCH => $source->url ? $this->link($source) : $this->finish($source, $source->text),
                Source::FILE => $this->file($source),
                default => $this->finish($source, $source->text),
            };
        } catch (UnsafeUrl $e) {
            $this->fail($source, $e->getMessage());
        } catch (ConnectionException) {
            $this->fail($source, 'That site did not respond in time.');
        } catch (RuntimeException $e) {
            $this->fail($source, $e->getMessage());
        }
    }

    private function link(Source $source): void
    {
        $url = (string) $source->url;

        if (SocialSites::isGoogleListing($url)) {
            $this->block($source, 'Google listings cannot be read automatically. Paste the address, phone number and opening hours as a note.');

            return;
        }

        $page = $this->fetcher->get($url);
        $social = SocialSites::isSocial($url);

        if ($social && $this->looksBlocked($page)) {
            $this->block($source, 'This site does not allow automatic reading. Paste the business details as a note instead.');

            return;
        }
        if ($page->status >= 400) {
            $this->fail($source, "The page returned an error ({$page->status}).");

            return;
        }

        if (str_contains($page->contentType, 'pdf')) {
            $this->finish($source, $this->pdf->readContent($page->body));

            return;
        }
        if (! str_contains($page->contentType, 'html') && ! str_contains($page->contentType, 'text/plain')) {
            $this->fail($source, 'That link is not a web page or a PDF.');

            return;
        }

        $text = str_contains($page->contentType, 'html') ? $this->html->read($page->body) : $page->body;
        if (trim($text) === '') {
            $this->fail($source, 'No readable text was found on that page.');

            return;
        }

        $this->finish($source, $text);
    }

    private function file(Source $source): void
    {
        $path = Storage::disk('local')->path((string) $source->path);

        if (! is_file($path)) {
            $this->fail($source, 'The uploaded file is missing.');

            return;
        }

        $mime = (string) $source->mime;

        if ($mime === 'application/pdf') {
            $this->finish($source, $this->pdf->readFile($path));
        } elseif (str_starts_with($mime, 'text/')) {
            $this->finish($source, mb_substr((string) file_get_contents($path), 0, PdfReader::MAX_CHARS));
        } else {
            // Photos carry no text to read. They are kept so the site can show them.
            $this->finish($source, null);
        }
    }

    private function looksBlocked(FetchedPage $page): bool
    {
        $loginWall = preg_match('#/(login|signin|accounts|checkpoint)#i', parse_url($page->finalUrl, PHP_URL_PATH) ?? '') === 1;

        return $page->status >= 400
            || $loginWall
            || $this->html->visibleLength($page->body) < self::SOCIAL_MIN_TEXT;
    }

    private function finish(Source $source, ?string $text): void
    {
        $source->update([
            'status' => Source::DONE,
            'text' => $text === null ? null : mb_scrub($text),
            'error' => null,
        ]);
    }

    private function fail(Source $source, string $message): void
    {
        $source->update(['status' => Source::FAILED, 'error' => mb_substr($message, 0, 300)]);
    }

    private function block(Source $source, string $message): void
    {
        $source->update(['status' => Source::BLOCKED, 'error' => $message]);
    }
}
