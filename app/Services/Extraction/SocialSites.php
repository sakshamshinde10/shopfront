<?php

namespace App\Services\Extraction;

/** Sites that need a login or block automated readers. We never try to get around that. */
final class SocialSites
{
    private const DOMAINS = [
        'instagram.com', 'facebook.com', 'fb.com', 'fb.me', 'linkedin.com', 'x.com',
        'twitter.com', 'tiktok.com', 'threads.net', 'snapchat.com', 'pinterest.com',
    ];

    public static function isSocial(string $url): bool
    {
        return self::matches($url, self::DOMAINS);
    }

    /** Google Maps and Business pages are drawn by JavaScript, so there is nothing to read. */
    public static function isGoogleListing(string $url): bool
    {
        $host = self::host($url);
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        if ($host === 'google.com' || str_ends_with($host, '.google.com')) {
            return str_contains($path, '/maps') || str_contains($path, '/search');
        }

        return self::matches($url, ['maps.app.goo.gl', 'goo.gl', 'g.page']);
    }

    private static function matches(string $url, array $domains): bool
    {
        $host = self::host($url);

        foreach ($domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    private static function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }
}
