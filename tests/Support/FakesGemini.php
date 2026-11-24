<?php

namespace Tests\Support;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Pretends to be Gemini. Each kind of request is answered from the handlers given, so a test can
 * decide exactly what the "AI" says, including bad answers. No real call is ever made.
 */
trait FakesGemini
{
    /** @var list<array<string, mixed>> every request body the fake received, in order */
    protected array $geminiCalls = [];

    /**
     * @param  array{
     *   places?: list<array<string, mixed>>,
     *   pages?: array<string, string>,
     *   extract?: callable(string): array,
     *   write?: array,
     *   image?: string,
     *   status?: int,
     *   places_status?: int,
     *   write_status?: int,
     * }  $handlers  the *_status options make that kind of call (or every call) fail with that HTTP status
     */
    protected function fakeGemini(array $handlers = []): void
    {
        config(['services.gemini.key' => 'test-key', 'services.gemini.models' => ['model-a', 'model-b']]);
        $this->geminiCalls = [];
        // Start from a clean fake, so a test can switch the "AI" from broken to working.
        Http::swap(new Factory);

        Http::fake(function (Request $request) use ($handlers) {
            // The place search (OpenStreetMap) is faked too, so tests never touch the network.
            if (str_contains($request->url(), 'nominatim.openstreetmap.org')) {
                return isset($handlers['places_status'])
                    ? Http::response('', $handlers['places_status'])
                    : Http::response($handlers['places'] ?? []);
            }
            // Ordinary web pages, keyed by a piece of the address, so a test can offer a website to read.
            foreach ($handlers['pages'] ?? [] as $address => $html) {
                if (str_contains($request->url(), $address)) {
                    return Http::response($html, 200, ['Content-Type' => 'text/html']);
                }
            }
            if (! str_contains($request->url(), 'generativelanguage.googleapis.com')) {
                return Http::response('', 404);
            }
            $this->geminiCalls[] = $request->data();

            if (isset($handlers['status'])) {
                return Http::response(['error' => ['message' => 'forced failure']], $handlers['status']);
            }

            $body = $request->data();
            $prompt = $body['contents'][0]['parts'][0]['text'] ?? '';
            $schema = $body['generationConfig']['responseSchema']['properties'] ?? null;

            $forced = isset($schema['tagline']) ? ($handlers['write_status'] ?? null) : null;
            if ($forced) {
                return Http::response(['error' => ['message' => 'forced failure']], $forced);
            }

            return match (true) {
                isset($schema['businesses']) => $this->geminiJson(($handlers['extract'] ?? fn () => ['businesses' => []])($prompt)),
                isset($schema['tagline']) => $this->geminiJson($handlers['write'] ?? ['tagline' => null, 'about' => [], 'services' => []]),
                default => $this->geminiText($handlers['image'] ?? 'NO TEXT'),
            };
        });
    }

    protected function geminiText(string $text)
    {
        return Http::response(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]]);
    }

    protected function geminiJson(array $payload)
    {
        return $this->geminiText(json_encode($payload));
    }

    /** One business with its facts, in the shape the extractor expects. */
    protected function business(array $facts, string $matches = 'yes', ?string $name = 'Sharma Bakery', ?string $city = 'Pune'): array
    {
        return [
            'name_seen' => $name,
            'city_seen' => $city,
            'matches' => $matches,
            'facts' => array_map(fn ($f) => ['field' => $f[0], 'value' => $f[1], 'quote' => $f[2] ?? $f[1]], $facts),
        ];
    }

    /** The prompts sent for fact extraction (not the search, writing or photo calls). */
    protected function extractionPrompts(): array
    {
        return collect($this->geminiCalls)
            ->filter(fn ($call) => isset($call['generationConfig']['responseSchema']['properties']['businesses']))
            ->map(fn ($call) => $call['contents'][0]['parts'][0]['text'])
            ->values()->all();
    }
}
