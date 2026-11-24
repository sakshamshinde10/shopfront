<?php

namespace Tests\Unit;

use App\Services\Extraction\CappedStream;
use App\Services\Extraction\HtmlReader;
use App\Services\Extraction\SafeFetcher;
use App\Services\Extraction\SocialSites;
use App\Services\Extraction\UnsafeUrl;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeHostResolver;
use Tests\TestCase;

class ExtractionTest extends TestCase
{
    #[DataProvider('socialLinks')]
    public function test_social_links_are_recognised(string $url, bool $expected): void
    {
        $this->assertSame($expected, SocialSites::isSocial($url));
    }

    public static function socialLinks(): array
    {
        return [
            ['https://www.instagram.com/shop', true],
            ['https://m.facebook.com/shop', true],
            ['https://x.com/shop', true],
            ['https://notinstagram.com/shop', false],
            ['https://sharmabakery.in', false],
        ];
    }

    public function test_google_listings_are_recognised(): void
    {
        $this->assertTrue(SocialSites::isGoogleListing('https://www.google.com/maps/place/Shop'));
        $this->assertTrue(SocialSites::isGoogleListing('https://maps.app.goo.gl/abc123'));
        $this->assertTrue(SocialSites::isGoogleListing('https://g.page/shop'));
        $this->assertFalse(SocialSites::isGoogleListing('https://www.google.com/about'));
        $this->assertFalse(SocialSites::isGoogleListing('https://sharmabakery.in'));
    }

    public function test_html_reader_keeps_real_content_and_drops_scripts_and_styles(): void
    {
        $text = (new HtmlReader)->read(
            '<html><head><title>Café Sharma</title><meta name="description" content="Fresh bread"></head>'
            .'<body><p>Open   daily</p><script>evil()</script><style>.a{}</style></body></html>'
        );

        $this->assertStringContainsString('Title: Café Sharma', $text);
        $this->assertStringContainsString('description: Fresh bread', $text);
        $this->assertStringContainsString('Open daily', $text);
        $this->assertStringNotContainsString('evil()', $text);
        $this->assertStringNotContainsString('.a{}', $text);
    }

    public function test_html_reader_limits_how_much_text_it_returns(): void
    {
        $text = (new HtmlReader)->read('<html><body>'.str_repeat('word ', 20000).'</body></html>');

        $this->assertLessThanOrEqual(HtmlReader::MAX_CHARS, mb_strlen($text));
    }

    public function test_the_fetcher_caps_how_much_it_downloads(): void
    {
        Http::fake(['big.test/*' => Http::response(str_repeat('a', 3_000_000), 200, ['Content-Type' => 'text/html'])]);

        $page = (new SafeFetcher(new FakeHostResolver))->get('https://big.test/');

        $this->assertLessThanOrEqual(1_000_000, strlen($page->body));
    }

    public function test_a_capped_stream_keeps_only_the_first_bytes(): void
    {
        $stream = new CappedStream(Utils::streamFor(fopen('php://temp', 'r+')), 10);

        $this->assertSame(8, $stream->write('12345678'));
        $this->assertSame(8, $stream->write('abcdefgh'));
        $this->assertSame(5, $stream->write('zzzzz'));

        $this->assertSame('12345678ab', (string) $stream);
    }

    public function test_the_fetcher_follows_a_safe_redirect(): void
    {
        Http::fake([
            'old.test/new' => Http::response('moved here', 200, ['Content-Type' => 'text/html']),
            'old.test/*' => Http::response('', 301, ['Location' => '/new']),
        ]);

        $page = (new SafeFetcher(new FakeHostResolver))->get('https://old.test/start');

        $this->assertSame('moved here', $page->body);
        $this->assertSame('https://old.test/new', $page->finalUrl);
    }

    public function test_the_fetcher_gives_up_on_endless_redirects(): void
    {
        Http::fake(['loop.test/*' => Http::response('', 302, ['Location' => 'https://loop.test/again'])]);

        $this->expectException(UnsafeUrl::class);

        (new SafeFetcher(new FakeHostResolver))->get('https://loop.test/');
    }
}
