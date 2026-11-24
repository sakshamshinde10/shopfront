<?php

namespace App\Services\Extraction;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Str;

/** Turns a web page into plain text the AI can use as evidence. */
class HtmlReader
{
    public const MAX_CHARS = 20000;

    public function read(string $html): string
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        // The prefix tells libxml the page is UTF-8, otherwise accents get garbled.
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($doc);
        $parts = [];

        if ($title = trim($xpath->evaluate('string(//title)'))) {
            $parts[] = 'Title: '.$title;
        }
        foreach (['description', 'og:description', 'og:title', 'og:site_name'] as $name) {
            $query = sprintf('string(//meta[@name="%1$s" or @property="%1$s"]/@content)', $name);
            if ($value = trim($xpath->evaluate($query))) {
                $parts[] = "{$name}: {$value}";
            }
        }

        // Many business sites describe themselves in machine-readable form. It is the most reliable source.
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $node) {
            $json = json_decode(trim($node->textContent), true);
            if (is_array($json)) {
                $parts[] = 'Structured data: '.json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        foreach ($xpath->query('//script|//style|//noscript|//svg|//iframe|//template') as $node) {
            $node->parentNode?->removeChild($node);
        }

        $body = $xpath->query('//body')->item(0);
        if ($body) {
            $parts[] = $this->clean($body->textContent);
        }

        return Str::limit(trim(implode("\n", array_filter($parts))), self::MAX_CHARS, '');
    }

    /** Length of the visible page text only, used to spot "log in to view" walls. */
    public function visibleLength(string $html): int
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($doc);
        foreach ($xpath->query('//script|//style|//noscript|//svg') as $node) {
            $node->parentNode?->removeChild($node);
        }

        return strlen($this->clean($xpath->evaluate('string(//body)')));
    }

    private function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
