<?php

namespace App\Services\Pipeline;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Looks a business up in OpenStreetMap (Nominatim). It needs no key and it returns every place that
 * matches the name, so two businesses with the same name show up as two results.
 */
class PlaceSearch
{
    private const URL = 'https://nominatim.openstreetmap.org/search';

    /**
     * @return list<array{name: string, type: string, address: string, phone: ?string, website: ?string, hours: ?string}>
     */
    public function find(string $name, ?string $city): array
    {
        $query = trim($name.' '.$city);

        // Their usage policy asks for few requests and a clear user agent, so answers are cached for a day.
        return Cache::remember('places:'.sha1(mb_strtolower($query)), now()->addDay(), fn () => $this->request($query));
    }

    /** @return list<array<string, mixed>> */
    private function request(string $query): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'Shopfront/1.0 (small business website builder)'])
                ->timeout(15)
                ->get(self::URL, ['q' => $query, 'format' => 'jsonv2', 'extratags' => 1, 'limit' => 5, 'dedupe' => 1]);
        } catch (ConnectionException) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json() ?? [])
            ->filter(fn ($place) => is_array($place) && filled($place['display_name'] ?? null))
            ->map(function (array $place) {
                $tags = (array) ($place['extratags'] ?? []);

                return [
                    'name' => (string) ($place['name'] ?? $place['display_name']),
                    'type' => trim(($place['category'] ?? '').' / '.($place['type'] ?? ''), ' /'),
                    'address' => (string) $place['display_name'],
                    'phone' => $tags['phone'] ?? $tags['contact:phone'] ?? null,
                    'website' => $tags['website'] ?? $tags['contact:website'] ?? null,
                    'hours' => $tags['opening_hours'] ?? null,
                ];
            })
            ->values()->all();
    }
}
