<?php

namespace Tests\Feature;

use App\Services\Ai\AiUnavailable;
use App\Services\Ai\GeminiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class GeminiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config(['services.gemini.key' => 'secret-key-123', 'services.gemini.models' => ['model-a', 'model-b']]);
    }

    private function reply(string $text, int $status = 200)
    {
        return Http::response(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]], $status);
    }

    public function test_the_key_goes_in_a_header_and_never_in_the_url(): void
    {
        Http::fake(['*' => $this->reply('{"ok":true}')]);

        $result = app(GeminiClient::class)->structured('sys', [['text' => 'hi']], ['type' => 'OBJECT']);

        $this->assertSame(['ok' => true], $result);
        Http::assertSent(fn (Request $r) => $r->hasHeader('x-goog-api-key', 'secret-key-123')
            && ! str_contains($r->url(), 'secret-key-123')
            && str_contains($r->url(), 'model-a'));
    }

    public function test_without_a_key_nothing_is_sent_and_the_message_says_what_to_do(): void
    {
        Http::fake();
        config(['services.gemini.key' => '']);

        try {
            app(GeminiClient::class)->answer('sys', 'hi');
            $this->fail('Expected an exception');
        } catch (AiUnavailable $e) {
            $this->assertStringContainsString('GEMINI_API_KEY', $e->getMessage());
            $this->assertFalse($e->retryable);
        }

        Http::assertNothingSent();
    }

    public function test_a_busy_model_falls_back_to_the_next_one(): void
    {
        Http::fake([
            '*model-a:*' => Http::response(['error' => ['message' => 'high demand']], 503),
            '*model-b:*' => $this->reply('{"from":"b"}'),
        ]);

        $this->assertSame(['from' => 'b'], app(GeminiClient::class)->structured('sys', [['text' => 'hi']], []));
    }

    public function test_a_model_that_does_not_answer_in_time_is_skipped_without_waiting_again(): void
    {
        Http::fake([
            '*model-a:*' => fn () => throw new ConnectionException('timed out'),
            '*model-b:*' => $this->reply('{"from":"b"}'),
        ]);

        $this->assertSame(['from' => 'b'], app(GeminiClient::class)->structured('sys', [['text' => 'hi']], []));
        Http::assertSentCount(1); // the slow one never completed, and it was not tried a second time
    }

    public function test_a_model_that_rejects_the_thinking_setting_is_asked_again_without_it(): void
    {
        Http::fake(fn (Request $r) => isset($r->data()['generationConfig']['thinkingConfig'])
            ? Http::response(['error' => ['message' => 'invalid argument']], 400)
            : $this->reply('{"ok":true}'));

        $this->assertSame(['ok' => true], app(GeminiClient::class)->structured('sys', [['text' => 'hi']], []));
    }

    public function test_a_retired_model_is_skipped(): void
    {
        Http::fake([
            '*model-a:*' => Http::response(['error' => ['message' => 'gone']], 404),
            '*model-b:*' => $this->reply('{"from":"b"}'),
        ]);

        $this->assertSame(['from' => 'b'], app(GeminiClient::class)->structured('sys', [['text' => 'hi']], []));
    }

    public function test_when_every_model_is_busy_the_error_is_friendly(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'overloaded']], 503)]);

        try {
            app(GeminiClient::class)->answer('sys', 'hi');
            $this->fail('Expected an exception');
        } catch (AiUnavailable $e) {
            $this->assertTrue($e->retryable);
            $this->assertStringNotContainsString('overloaded', $e->getMessage());
        }
    }

    public function test_a_rejected_key_stops_at_once_and_never_leaks_into_the_message(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'API key secret-key-123 not valid']], 403)]);

        try {
            app(GeminiClient::class)->answer('sys', 'hi');
            $this->fail('Expected an exception');
        } catch (AiUnavailable $e) {
            $this->assertFalse($e->retryable);
            $this->assertStringNotContainsString('secret-key-123', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_an_answer_that_is_not_json_is_an_error(): void
    {
        Http::fake(['*' => $this->reply('Sorry, I cannot do that')]);

        $this->expectException(AiUnavailable::class);

        app(GeminiClient::class)->structured('sys', [['text' => 'hi']], []);
    }

    public function test_an_empty_or_blocked_answer_is_an_error(): void
    {
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => []]]]])]);
        $this->expectException(AiUnavailable::class);
        app(GeminiClient::class)->answer('sys', 'hi');
    }

    public function test_blocked_content_is_not_retried(): void
    {
        Http::fake(['*' => Http::response(['promptFeedback' => ['blockReason' => 'SAFETY']])]);

        try {
            app(GeminiClient::class)->answer('sys', 'hi');
            $this->fail('Expected an exception');
        } catch (AiUnavailable $e) {
            $this->assertFalse($e->retryable);
        }
    }

    public function test_a_plain_answer_returns_the_text(): void
    {
        Http::fake(['*' => $this->reply('Hello there')]);

        $this->assertSame('Hello there', app(GeminiClient::class)->answer('sys', 'hi'));
    }

    public function test_a_photo_is_sent_as_inline_data_and_its_text_returned(): void
    {
        Http::fake(['*' => $this->reply('  Menu: Plum cake 250 ')]);

        $text = app(GeminiClient::class)->readImage('sys', 'image/png', 'PNGDATA');

        $this->assertSame('Menu: Plum cake 250', $text);
        Http::assertSent(fn (Request $r) => ($r->data()['contents'][0]['parts'][1]['inline_data']['data'] ?? null) === base64_encode('PNGDATA'));
    }
}
