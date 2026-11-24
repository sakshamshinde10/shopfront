<?php

namespace App\Services\Pipeline;

/** What a writing run did: whether the AI wrote the text, and which sections were really saved. */
final class WriteOutcome
{
    /** @param  list<string>  $written  keys of the sections that were saved */
    public function __construct(
        public readonly bool $usedAi,
        public readonly array $written,
    ) {}
}
