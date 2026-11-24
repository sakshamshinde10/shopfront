<?php

namespace App\Services\Ai;

/**
 * The AI declined to read one particular piece of content. Unlike a missing key or an outage,
 * this only affects that one source, so the rest of the site can still be built.
 */
class AiRefusedContent extends AiUnavailable
{
    public function __construct(string $message = 'The AI declined to process this content.')
    {
        parent::__construct($message, retryable: false);
    }
}
