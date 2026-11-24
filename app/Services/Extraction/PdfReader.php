<?php

namespace App\Services\Extraction;

use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;
use Throwable;

class PdfReader
{
    public const MAX_CHARS = 30000;

    /** @throws \RuntimeException when the PDF cannot be read or has no text */
    public function readFile(string $absolutePath): string
    {
        return $this->clean(fn () => (new Parser)->parseFile($absolutePath)->getText());
    }

    /** @throws \RuntimeException */
    public function readContent(string $binary): string
    {
        return $this->clean(fn () => (new Parser)->parseContent($binary)->getText());
    }

    private function clean(callable $extract): string
    {
        try {
            $text = $extract();
        } catch (Throwable) {
            throw new \RuntimeException('This PDF could not be read.');
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
        if ($text === '') {
            throw new \RuntimeException('This PDF has no readable text (it may be a scan). Paste the key details as a note instead.');
        }

        return Str::limit($text, self::MAX_CHARS, '');
    }
}
