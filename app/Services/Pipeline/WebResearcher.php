<?php

namespace App\Services\Pipeline;

use App\Models\Site;
use App\Models\Source;
use App\Services\Extraction\SourceExtractor;

/**
 * Looks for the business on the web and records what it finds as ordinary sources: one list of
 * matching places, plus the website of each place. They are checked like anything else, and if
 * several businesses share the name the owner is asked which one is theirs.
 * This step is a bonus: if it finds nothing or fails, the rest of the work carries on.
 */
class WebResearcher
{
    private const MAX_WEBSITES = 3;

    public function __construct(
        private readonly PlaceSearch $places,
        private readonly SourceExtractor $extractor,
    ) {}

    public function research(Site $site): void
    {
        if ($site->sources()->where('kind', Source::SEARCH)->exists()) {
            return;
        }

        $places = $this->places->find($site->business_name, $site->city);

        $site->sources()->create([
            'kind' => Source::SEARCH,
            'label' => 'Web search',
            'status' => Source::DONE,
            'text' => $places === [] ? null : $this->describe($site, $places),
            // Nothing found means there is nothing to read.
            'analysed_at' => $places === [] ? now() : null,
        ]);

        $this->readWebsites($site, $places);
    }

    /** @param list<array<string, mixed>> $places */
    private function describe(Site $site, array $places): string
    {
        $lines = ["Places named \"{$site->business_name}\" found on OpenStreetMap".($site->city ? " near {$site->city}" : '').':'];

        foreach ($places as $i => $place) {
            $lines[] = '';
            $lines[] = 'Result '.($i + 1);
            $lines[] = "Name: {$place['name']}";
            $lines[] = "Type: {$place['type']}";
            $lines[] = "Address: {$place['address']}";
            foreach (['phone' => 'Phone', 'website' => 'Website', 'hours' => 'Opening hours'] as $key => $label) {
                if (filled($place[$key])) {
                    $lines[] = "{$label}: {$place[$key]}";
                }
            }
        }

        return implode("\n", $lines);
    }

    /** Read the business websites found. A page that cannot be read is simply marked as failed. */
    private function readWebsites(Site $site, array $places): void
    {
        $websites = collect($places)
            ->filter(fn ($place) => filled($place['website']) && preg_match('#^https?://#i', $place['website']))
            ->unique('website')
            ->take(self::MAX_WEBSITES);

        foreach ($websites as $place) {
            $source = $site->sources()->create([
                'kind' => Source::SEARCH,
                'label' => 'Website of '.$place['name'],
                'url' => $place['website'],
                'status' => Source::PENDING,
            ]);

            // Read it now, so it is ready to analyse in the same run.
            $this->extractor->run($source);
        }
    }
}
