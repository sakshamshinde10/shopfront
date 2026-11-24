<?php

namespace App\Services\Extraction;

final class FetchedPage
{
    public function __construct(
        public readonly int $status,
        public readonly string $contentType,
        public readonly string $body,
        public readonly string $finalUrl,
    ) {}
}
