<?php

namespace App\Services\Pipeline;

use App\Models\Fact;
use App\Models\Site;
use App\Models\Source;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\GeminiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Turns one source into candidate facts. A fact is only kept if the AI can show where the
 * source says it, and we can find that exact quote ourselves.
 */
class FactExtractor
{
    private const MAX_SOURCE_CHARS = 12000;

    private const MAX_BUSINESSES = 5;

    private const MAX_FACTS_PER_BUSINESS = 40;

    private const MAX_IMAGE_BYTES = 4_000_000;

    public function __construct(private readonly GeminiClient $ai) {}

    /** @throws AiUnavailable */
    public function analyse(Site $site, Source $source): void
    {
        $text = (string) $source->text;

        if ($text === '' && str_starts_with((string) $source->mime, 'image/')) {
            $text = $this->readPhoto($source);
        }

        $text = mb_substr($text, 0, self::MAX_SOURCE_CHARS);
        $warning = InstructionScanner::warning($text);
        // Everything below works on the cleaned text: that is all the AI sees and all a fact can be proven from.
        $text = $warning ? InstructionScanner::strip($text) : $text;

        if (trim($text) === '') {
            $source->update(['analysed_at' => now(), 'warning' => $warning]);

            return;
        }

        $result = $this->ai->structured(
            Prompts::EXTRACT_SYSTEM,
            [['text' => $this->prompt($site, $source, $text)]],
            Prompts::extractSchema(),
        );

        $this->save($site, $source, $text, $result, $warning);
    }

    private function save(Site $site, Source $source, string $text, array $result, ?string $warning): void
    {
        DB::transaction(function () use ($site, $source, $text, $result, $warning) {
            // Run again from scratch if this source is analysed twice.
            $source->facts()->where('status', Fact::CANDIDATE)->delete();

            $businesses = [];
            foreach (array_slice($result['businesses'] ?? [], 0, self::MAX_BUSINESSES) as $index => $business) {
                $businesses[] = [
                    'index' => $index,
                    'name' => $this->short($business['name_seen'] ?? null),
                    'city' => $this->short($business['city_seen'] ?? null),
                    // Whatever the owner typed themselves is, by definition, about their business.
                    'matches' => $source->kind === Source::NOTE ? 'yes' : ($business['matches'] ?? 'unsure'),
                ];

                foreach (array_slice($business['facts'] ?? [], 0, self::MAX_FACTS_PER_BUSINESS) as $fact) {
                    if ($this->isProven($fact, $text)) {
                        $site->facts()->create([
                            'source_id' => $source->id,
                            'business' => $index,
                            'field' => $fact['field'],
                            'value' => trim($fact['value']),
                            'status' => Fact::CANDIDATE,
                        ]);
                    }
                }
            }

            $source->update([
                'identity' => ['businesses' => $businesses],
                'warning' => $warning,
                'analysed_at' => now(),
            ]);
        });
    }

    /** The proof check: right field, sensible size, the quote is in the source, and so is the value. */
    private function isProven(mixed $fact, string $text): bool
    {
        if (! is_array($fact)
            || ! in_array($fact['field'] ?? null, Fact::FIELDS, true)
            || ! is_string($fact['value'] ?? null)
            || ! is_string($fact['quote'] ?? null)) {
            return false;
        }

        $value = trim($fact['value']);

        return $value !== ''
            && mb_strlen($value) <= 300
            && mb_strlen($fact['quote']) <= 400
            && Grounding::quoteIsInSource($fact['quote'], $text)
            && Grounding::valueIsInSource($fact['field'], $value, $text);
    }

    private function prompt(Site $site, Source $source, string $text): string
    {
        $owner = $site->business_name.($site->city ? ", {$site->city}" : ' (city not given)');
        // The text must not be able to close the data block and start talking to the AI.
        $safe = str_ireplace(['<source', '</source'], ['<\source', '<\/source'], $text);
        $label = str_replace('"', "'", (string) $source->label);

        return "The owner's business: {$owner}\n\n<source kind=\"{$source->kind}\" label=\"{$label}\">\n{$safe}\n</source>";
    }

    /** A photo has no text yet, so the AI reads it first. The text it returns is checked like any other source. */
    private function readPhoto(Source $source): string
    {
        $disk = Storage::disk('local');

        if (! $source->path || ! $disk->exists($source->path) || $disk->size($source->path) > self::MAX_IMAGE_BYTES) {
            return '';
        }

        $text = $this->ai->readImage(Prompts::IMAGE_SYSTEM, (string) $source->mime, $disk->get($source->path));

        if ($text === '' || str_contains(strtoupper($text), 'NO TEXT')) {
            return '';
        }

        $source->update(['text' => mb_scrub($text)]);

        return $text;
    }

    private function short(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 150) : null;
    }
}
