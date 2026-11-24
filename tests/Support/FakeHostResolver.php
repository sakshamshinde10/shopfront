<?php

namespace Tests\Support;

use App\Services\Extraction\HostResolver;

/** Answers DNS lookups from a fixed list, so tests never touch the network. */
class FakeHostResolver extends HostResolver
{
    /** @param array<string, list<string>> $map host => IPs; any other host gets a public address */
    public function __construct(private array $map = []) {}

    public function resolve(string $host): array
    {
        return $this->map[$host] ?? ['93.184.216.34'];
    }
}
