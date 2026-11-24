<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * The AI could not give us a usable answer: no key, key rejected, rate limit,
 * outage, or an answer we cannot read. The message is safe to show to the owner.
 */
class AiUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = true)
    {
        parent::__construct($message);
    }
}
