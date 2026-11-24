<?php

namespace App\Services\Extraction;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Downloads a page the owner pointed us at.
 *
 * Because the address comes from a user, it could point at our own server or
 * a private network (SSRF). So: only http(s) on ports 80/443, the host must
 * resolve to public addresses only, every redirect is checked again, the
 * connection is pinned to the address we checked, and the body is size-capped.
 */
class SafeFetcher
{
    private const MAX_BYTES = 1_000_000;

    private const MAX_REDIRECTS = 3;

    public function __construct(private readonly HostResolver $resolver) {}

    /** @throws UnsafeUrl|ConnectionException */
    public function get(string $url): FetchedPage
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$host, $port, $ip] = $this->check($url);

            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; ShopfrontBot/1.0)',
                'Accept' => 'text/html,application/xhtml+xml,application/pdf;q=0.9,*/*;q=0.5',
            ])
                ->timeout(10)
                ->connectTimeout(5)
                ->withOptions([
                    'allow_redirects' => false,
                    // Anything past the limit is dropped as it arrives, so memory stays small.
                    // (Not "stream => true": that switches Guzzle to a handler that cannot pin the IP.)
                    'sink' => new CappedStream(Utils::streamFor(fopen('php://temp', 'r+')), self::MAX_BYTES),
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
                ])
                ->get($url);

            if ($response->redirect() && $response->header('Location') !== '') {
                $url = $this->absolute($url, $response->header('Location'));

                continue;
            }

            return new FetchedPage(
                $response->status(),
                strtolower($response->header('Content-Type')),
                substr($response->body(), 0, self::MAX_BYTES),
                $url,
            );
        }

        throw new UnsafeUrl('The link redirects too many times.');
    }

    /** @return array{0:string,1:int,2:string} host, port, the public IP to connect to */
    private function check(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new UnsafeUrl('Only http and https links can be read.');
        }
        if (str_starts_with($host, '[')) {
            throw new UnsafeUrl('IPv6 addresses are not supported.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrl('Links with a user name or password are not allowed.');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! in_array($port, [80, 443], true)) {
            throw new UnsafeUrl('Only the standard web ports are allowed.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolver->resolve($host);
        if ($ips === []) {
            throw new UnsafeUrl('That address could not be found.');
        }

        foreach ($ips as $ip) {
            $isPublic = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($isPublic === false || $this->isSharedAddressSpace($ip)) {
                throw new UnsafeUrl('That address is not allowed.');
            }
        }

        return [$host, $port, $ips[0]];
    }

    /** 100.64.0.0/10 is carrier-grade NAT: not public, but PHP's filter lets it through. */
    private function isSharedAddressSpace(string $ip): bool
    {
        $long = ip2long($ip);

        return $long !== false && $long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255');
    }

    private function absolute(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'http').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'http').':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        return $origin.rtrim(dirname($parts['path'] ?? '/'), '/').'/'.$location;
    }
}
