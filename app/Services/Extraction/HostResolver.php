<?php

namespace App\Services\Extraction;

/** Looks up a host name. Kept in its own class so tests can replace it. */
class HostResolver
{
    /** @return list<string> IPv4 addresses, empty when the name does not resolve */
    public function resolve(string $host): array
    {
        $ips = gethostbynamel($host);

        return $ips === false ? [] : array_values($ips);
    }
}
