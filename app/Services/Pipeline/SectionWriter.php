<?php

namespace App\Services\Pipeline;

use App\Models\Fact;
use App\Models\Section;
use App\Models\Site;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\GeminiClient;
use Illuminate\Support\Collection;

/**
 * Builds the sections of the website from confirmed facts only.
 *
 * Contact details, services and prices are copied straight from the facts, never written by
 * the AI. The AI only phrases the tagline, the "about" text and short service descriptions,
 * and every sentence it produces is checked against the facts before it is kept.
 */
class SectionWriter
{
    public function __construct(private readonly GeminiClient $ai) {}

    /**
     * @param  list<string>|null  $only  rewrite just these sections
     * @param  bool  $force  the owner asked for this: replace even sections they edited, and fail
     *                       instead of quietly falling back to the plain version when the AI is down
     *
     * @throws AiUnavailable only when $force is set and the AI was needed
     */
    public function write(Site $site, ?array $only = null, bool $force = false): WriteOutcome
    {
        $facts = $site->facts()->where('status', Fact::CONFIRMED)->get()->groupBy('field');
        $evidence = $this->evidence($site, $facts);
        $usedAi = true;
        $prose = ['tagline' => null, 'about' => [], 'services' => []];

        // The contact section is copied from the facts and never needs the AI. Otherwise only ask
        // the AI when there is something to write about.
        $needsProse = $only === null || array_intersect($only, ['hero', 'about', 'services']) !== [];

        if ($needsProse && ($facts->has('about') || $facts->has('service'))) {
            try {
                $prose = $this->prose($site, $facts, $evidence);
            } catch (AiUnavailable $e) {
                if ($force) {
                    throw $e;
                }
                $usedAi = false;
            }
        }

        $sections = [
            'hero' => ['title' => $site->business_name, 'city' => $site->city, 'tagline' => $this->tagline($prose, $evidence)],
            'about' => ['paragraphs' => $this->paragraphs($facts, $prose, $evidence)],
            'services' => ['items' => $this->services($facts, $prose, $evidence), 'prices' => $this->values($facts, 'price')],
            'contact' => $this->contact($facts),
        ];

        $written = [];
        foreach (Section::KEYS as $position => $key) {
            if ($only !== null && ! in_array($key, $only, true)) {
                continue;
            }
            if ($this->store($site, $key, $position, $sections[$key], $force)) {
                $written[] = $key;
            }
        }

        return new WriteOutcome($usedAi, $written);
    }

    /** @return array{tagline: ?string, about: list<string>, services: list<array{name: string, description: ?string}>} */
    private function prose(Site $site, Collection $facts, string $evidence): array
    {
        // The writer only gets what the business is and what it offers. Phone, hours, address and prices
        // are shown in their own places on the page, so the AI never sees them and cannot repeat them.
        $list = $facts->filter(fn ($group, $field) => in_array($field, ['about', 'service'], true))->flatten()
            ->map(fn (Fact $f) => ['field' => $f->field, 'value' => $f->value])
            ->values()->all();

        $result = $this->ai->structured(
            Prompts::WRITE_SYSTEM,
            [['text' => "Business name: {$site->business_name}\nCity: ".($site->city ?: 'not given')."\n\nConfirmed facts (JSON):\n".json_encode($list, JSON_UNESCAPED_UNICODE)]],
            Prompts::writeSchema(),
        );

        return [
            'tagline' => is_string($result['tagline'] ?? null) ? $result['tagline'] : null,
            'about' => array_values(array_filter((array) ($result['about'] ?? []), 'is_string')),
            'services' => array_values(array_filter((array) ($result['services'] ?? []), 'is_array')),
        ];
    }

    private function tagline(array $prose, string $evidence): ?string
    {
        $tagline = $prose['tagline'];

        return $tagline && Grounding::unsupported($tagline, $evidence) === [] ? trim($tagline) : null;
    }

    /** @return list<string> */
    private function paragraphs(Collection $facts, array $prose, string $evidence): array
    {
        $written = collect($prose['about'])
            ->map(fn (string $p) => Grounding::keepSupportedSentences($p, $evidence))
            ->filter()->values();

        // Without usable AI text, show what the sources said, word for word.
        return $written->isNotEmpty() ? $written->all() : $this->values($facts, 'about');
    }

    /** @return list<array{name: string, description: ?string}> */
    private function services(Collection $facts, array $prose, string $evidence): array
    {
        $described = collect($prose['services'])
            ->filter(fn ($s) => is_string($s['name'] ?? null))
            ->keyBy(fn ($s) => Grounding::sameValueKey('service', $s['name']));

        // The list of services comes from the facts. The AI can only add a description to one of them.
        return $facts->get('service', collect())->map(function (Fact $fact) use ($described, $evidence) {
            $description = $described->get(Grounding::sameValueKey('service', $fact->value))['description'] ?? null;
            $description = is_string($description) ? Grounding::keepSupportedSentences($description, $evidence) : '';

            return ['name' => $fact->value, 'description' => $description !== '' ? $description : null];
        })->values()->all();
    }

    private function contact(Collection $facts): array
    {
        return array_filter([
            'phone' => $this->values($facts, 'phone')[0] ?? null,
            'email' => $this->values($facts, 'email')[0] ?? null,
            'address' => $this->values($facts, 'address')[0] ?? null,
            'hours' => $this->values($facts, 'hours')[0] ?? null,
            'social' => $this->values($facts, 'social'),
        ]);
    }

    /** @return list<string> */
    private function values(Collection $facts, string $field): array
    {
        return $facts->get($field, collect())->pluck('value')->unique()->values()->all();
    }

    /** Everything the writer is allowed to be consistent with. */
    private function evidence(Site $site, Collection $facts): string
    {
        return $site->business_name.' '.$site->city.' '.$facts->flatten()->pluck('value')->implode(' ');
    }

    /** @return bool true when the section was saved */
    private function store(Site $site, string $key, int $position, array $content, bool $force): bool
    {
        $existing = $site->sections()->where('key', $key)->first();

        // The owner's own edits are only replaced when they ask for it.
        if ($existing?->edited_by_owner && ! $force) {
            return false;
        }

        if (! $this->hasContent($key, $content)) {
            // Nothing to write from. A normal run tidies up; an explicit request never deletes anything.
            if (! $force) {
                $existing?->delete();
            }

            return false;
        }

        $site->sections()->updateOrCreate(
            ['key' => $key],
            ['position' => $position, 'content' => $content, 'edited_by_owner' => false],
        );

        return true;
    }

    private function hasContent(string $key, array $content): bool
    {
        return match ($key) {
            'hero' => true,
            'about' => $content['paragraphs'] !== [],
            'services' => $content['items'] !== [] || $content['prices'] !== [],
            default => $content !== [],
        };
    }
}
