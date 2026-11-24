<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The only class that talks to Gemini. It keeps the key in a header (never in a
 * URL that could end up in logs), retries short outages, and turns every failure
 * into an AiUnavailable with a message that is safe to show to the owner.
 */
class GeminiClient
{
    public function __construct(
        private readonly ?string $key = null,
        /** @var list<string>|null */
        private readonly ?array $models = null,
    ) {}

    /**
     * Ask for an answer that must match a JSON schema.
     *
     * @param  array<int, array<string, mixed>>  $parts  Gemini "parts": text and/or inline images
     * @return array<string, mixed>
     *
     * @throws AiUnavailable
     */
    public function structured(string $system, array $parts, array $schema, int $maxTokens = 4096): array
    {
        $response = $this->send([
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => $maxTokens,
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
                'thinkingConfig' => ['thinkingBudget' => 0],
            ],
        ]);

        $decoded = json_decode($this->text($response), true);

        if (! is_array($decoded)) {
            throw new AiUnavailable('The AI returned an answer we could not read. Please try again.');
        }

        return $decoded;
    }

    /**
     * A plain text answer.
     *
     * @throws AiUnavailable
     */
    public function answer(string $system, string $prompt): string
    {
        $response = $this->send([
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 2048],
        ]);

        return $this->text($response);
    }

    /**
     * Read the text in a photo (a menu, a price list, a sign).
     *
     * @throws AiUnavailable
     */
    public function readImage(string $system, string $mime, string $binary): string
    {
        $response = $this->send([
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [
                ['text' => 'Copy the text in this image.'],
                ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($binary)]],
            ]]],
            'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 2048],
        ]);

        return trim($this->text($response));
    }

    /** A one-word call to prove the key works, used for setup checks. */
    public function ping(): void
    {
        $this->answer('Reply with the single word: ok', 'ping');
    }

    private function send(array $body): Response
    {
        $key = $this->key ?? config('services.gemini.key');
        if (! is_string($key) || trim($key) === '') {
            throw new AiUnavailable('The AI is not set up yet. Add GEMINI_API_KEY to the .env file.', retryable: false);
        }

        $models = $this->models ?? config('services.gemini.models');
        $failure = null;

        // A busy or retired model should not stop the whole app, so try the next one.
        foreach ($models as $model) {
            try {
                return $this->checked($this->post(trim($key), $model, $body));
            } catch (AiUnavailable $e) {
                $failure = $e;
                if (! $e->retryable) {
                    throw $e;
                }
            }
        }

        throw $failure ?? new AiUnavailable('No AI model is configured. Set GEMINI_MODELS in the .env file.', retryable: false);
    }

    private function post(string $key, string $model, array $body): Response
    {
        $url = rtrim(config('services.gemini.base_url'), '/')."/models/{$model}:generateContent";

        try {
            $response = $this->request($key)->post($url, $body);

            // Some models (the "lite" ones) do not accept the thinking setting. Ask again without it.
            if ($response->status() === 400 && isset($body['generationConfig']['thinkingConfig'])) {
                unset($body['generationConfig']['thinkingConfig']);
                $response = $this->request($key)->post($url, $body);
            }

            return $response;
        } catch (ConnectionException) {
            // A model that does not answer in time is not worth waiting for again: the next one may be fine.
            throw new AiUnavailable('The AI service did not respond. Your sources are saved, please try again in a moment.');
        }
    }

    private function request(string $key): PendingRequest
    {
        return Http::withHeaders(['x-goog-api-key' => $key])
            ->acceptJson()
            ->timeout((int) config('services.gemini.timeout', 40))
            // One short retry for "busy" answers only. Timeouts are not retried; we move to the next model instead.
            ->retry(
                [1000],
                when: fn ($e) => $e instanceof RequestException && ($e->response->status() === 429 || $e->response->serverError()),
                throw: false,
            );
    }

    private function checked(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        // Never include the request or response body in the message: it may echo the key.
        throw match (true) {
            // A missing model (retired or misspelled) is worth trying the next model for.
            $response->status() === 404 => new AiUnavailable('That AI model is not available.'),
            in_array($response->status(), [400, 401, 403], true) => new AiUnavailable(
                'The AI rejected our request. Check that GEMINI_API_KEY is valid.',
                retryable: false,
            ),
            $response->status() === 429 => new AiUnavailable('The AI is busy right now (rate limit). Please try again in a minute.'),
            default => new AiUnavailable('The AI service is having problems. Please try again in a moment.'),
        };
    }

    private function text(Response $response): string
    {
        if ($response->json('promptFeedback.blockReason')) {
            throw new AiRefusedContent;
        }

        $text = collect($response->json('candidates.0.content.parts', []))
            ->pluck('text')->filter()->implode('');

        if (trim($text) === '') {
            throw new AiUnavailable('The AI returned an empty answer. Please try again.');
        }

        return $text;
    }
}
