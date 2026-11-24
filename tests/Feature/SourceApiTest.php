<?php

namespace Tests\Feature;

use App\Models\Fact;
use App\Models\Site;
use App\Models\Source;
use App\Services\SourceIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesSites;
use Tests\TestCase;

class SourceApiTest extends TestCase
{
    use CreatesSites;
    use RefreshDatabase;

    private int $siteId;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDns();
        Storage::fake('local');
        [$this->siteId, $this->token] = $this->createSite();
    }

    private function addLink(string $url)
    {
        return $this->asOwner($this->token)->postJson("/api/sites/{$this->siteId}/sources", ['kind' => 'link', 'url' => $url]);
    }

    private function source(): Source
    {
        return Source::where('site_id', $this->siteId)->latest('id')->firstOrFail();
    }

    // ---- links -----------------------------------------------------------

    public function test_a_web_page_is_read_including_its_structured_data(): void
    {
        $html = '<html><head><title>Sharma Bakery</title>
            <script type="application/ld+json">{"@type":"Bakery","telephone":"+91 98765 43210"}</script>
            <style>body{color:red}</style></head>
            <body><h1>Fresh bread daily</h1><script>alert(1)</script></body></html>';
        Http::fake(['sharmabakery.test/*' => Http::response($html, 200, ['Content-Type' => 'text/html; charset=utf-8'])]);

        $this->addLink('https://sharmabakery.test/about')->assertCreated()->assertJsonPath('data.kind', 'link');

        $source = $this->source();
        $this->assertSame(Source::DONE, $source->status);
        $this->assertStringContainsString('Fresh bread daily', $source->text);
        $this->assertStringContainsString('+91 98765 43210', $source->text);
        $this->assertStringNotContainsString('alert(1)', $source->text);
        $this->assertStringNotContainsString('color:red', $source->text);
    }

    public function test_a_social_page_that_asks_for_login_is_marked_blocked_not_failed(): void
    {
        Http::fake(['www.instagram.com/*' => Http::response('<html><body>Log in to continue</body></html>', 200, ['Content-Type' => 'text/html'])]);

        $this->addLink('https://www.instagram.com/sharmabakery')->assertCreated();

        $source = $this->source();
        $this->assertSame(Source::BLOCKED, $source->status);
        $this->assertStringContainsString('Paste the business details', $source->error);
    }

    public function test_a_social_site_that_refuses_us_is_blocked(): void
    {
        Http::fake(['www.facebook.com/*' => Http::response('', 429)]);

        $this->addLink('https://www.facebook.com/sharmabakery');

        $this->assertSame(Source::BLOCKED, $this->source()->status);
    }

    public function test_a_google_listing_is_not_fetched_at_all(): void
    {
        Http::fake();

        $this->addLink('https://www.google.com/maps/place/Sharma+Bakery')->assertCreated();

        Http::assertNothingSent();
        $this->assertSame(Source::BLOCKED, $this->source()->status);
        $this->assertStringContainsString('opening hours', $this->source()->error);
    }

    public function test_an_error_page_is_marked_failed_with_a_reason(): void
    {
        Http::fake(['gone.test/*' => Http::response('Not found', 404)]);

        $this->addLink('https://gone.test/page');

        $this->assertSame(Source::FAILED, $this->source()->status);
        $this->assertStringContainsString('404', $this->source()->error);
    }

    public function test_a_slow_site_fails_cleanly(): void
    {
        Http::fake(['slow.test/*' => fn () => throw new ConnectionException('timeout')]);

        $this->addLink('https://slow.test/');

        $this->assertSame(Source::FAILED, $this->source()->status);
        $this->assertStringContainsString('did not respond', $this->source()->error);
    }

    public function test_links_to_private_or_internal_addresses_are_never_opened(): void
    {
        $this->fakeDns(['localhost' => ['127.0.0.1']]);
        Http::fake();

        foreach ([
            'http://127.0.0.1/admin',
            'http://localhost/',
            'http://10.0.0.5/',
            'http://192.168.1.1/',
            'http://169.254.169.254/latest/meta-data/',
            'http://100.64.0.1/',
            'http://[::1]/',
        ] as $url) {
            $this->addLink($url)->assertCreated();
            $this->assertSame(Source::FAILED, $this->source()->status, $url);
        }

        Http::assertNothingSent();
    }

    public function test_a_name_that_resolves_to_a_private_address_is_refused(): void
    {
        $this->fakeDns(['sneaky.test' => ['10.0.0.2']]);
        Http::fake();

        $this->addLink('https://sneaky.test/');

        $this->assertSame(Source::FAILED, $this->source()->status);
        Http::assertNothingSent();
    }

    public function test_a_redirect_into_a_private_network_is_refused(): void
    {
        Http::fake(['evil.test/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/secret'])]);

        $this->addLink('https://evil.test/go');

        $this->assertSame(Source::FAILED, $this->source()->status);
        Http::assertSentCount(1);
    }

    public function test_urls_with_credentials_or_odd_ports_are_refused(): void
    {
        Http::fake();

        $this->addLink('https://user:pass@example.test/');
        $this->assertSame(Source::FAILED, $this->source()->status);

        $this->addLink('https://example.test:8080/');
        $this->assertSame(Source::FAILED, $this->source()->status);

        Http::assertNothingSent();
    }

    public function test_adding_the_same_link_twice_does_not_duplicate_it(): void
    {
        Http::fake(['dup.test/*' => Http::response('<html><body>Hello</body></html>', 200, ['Content-Type' => 'text/html'])]);

        $first = $this->addLink('https://dup.test/')->json('data.id');
        $second = $this->addLink('https://dup.test/')->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Source::where('site_id', $this->siteId)->where('url', 'https://dup.test/')->count());
    }

    // ---- notes -----------------------------------------------------------

    public function test_a_note_is_saved_as_read(): void
    {
        $this->asOwner($this->token)->postJson("/api/sites/{$this->siteId}/sources", [
            'kind' => 'note', 'text' => 'Call us on 98765 43210', 'label' => 'Phone',
        ])->assertCreated()->assertJsonPath('data.status', Source::DONE);

        $this->assertSame('Call us on 98765 43210', $this->source()->text);
    }

    public function test_source_requests_are_validated(): void
    {
        $url = "/api/sites/{$this->siteId}/sources";

        $this->asOwner($this->token)->postJson($url, ['kind' => 'video'])->assertUnprocessable();
        $this->asOwner($this->token)->postJson($url, ['kind' => 'link'])->assertUnprocessable()->assertJsonValidationErrors('url');
        $this->asOwner($this->token)->postJson($url, ['kind' => 'note'])->assertUnprocessable()->assertJsonValidationErrors('text');
    }

    public function test_a_site_cannot_hold_more_than_the_allowed_number_of_sources(): void
    {
        $site = Site::findOrFail($this->siteId);
        for ($i = 0; $i < SourceIntake::MAX_SOURCES; $i++) {
            app(SourceIntake::class)->note($site, "note {$i}");
        }

        $this->asOwner($this->token)->postJson("/api/sites/{$this->siteId}/sources", ['kind' => 'note', 'text' => 'one too many'])
            ->assertUnprocessable()->assertJsonValidationErrors('sources');
    }

    // ---- files -----------------------------------------------------------

    private function upload(array $files)
    {
        return $this->asOwner($this->token)->post("/api/sites/{$this->siteId}/files", ['files' => $files], ['Accept' => 'application/json']);
    }

    public function test_a_pdf_is_stored_privately_and_its_text_is_read(): void
    {
        $pdf = UploadedFile::fake()->createWithContent('menu.pdf', $this->pdfWithText('Sharma Bakery open 9 to 9'));

        $this->upload([$pdf])->assertCreated()->assertJsonPath('data.0.label', 'menu.pdf');

        $source = $this->source();
        $this->assertSame(Source::DONE, $source->status);
        $this->assertStringContainsString('Sharma Bakery open 9 to 9', $source->text);
        Storage::disk('local')->assertExists($source->path);
        $this->assertStringStartsWith("sources/{$this->siteId}/", $source->path);
    }

    public function test_the_response_never_exposes_the_file_path_or_raw_text(): void
    {
        $pdf = UploadedFile::fake()->createWithContent('menu.pdf', $this->pdfWithText('Secret price list'));

        $response = $this->upload([$pdf])->assertCreated();

        $this->assertStringNotContainsString('sources/', $response->getContent());
        $this->assertStringNotContainsString('Secret price list', $response->getContent());
    }

    public function test_a_photo_is_kept_without_text(): void
    {
        $this->upload([UploadedFile::fake()->image('shopfront.jpg', 600, 400)])->assertCreated();

        $source = $this->source();
        $this->assertSame(Source::DONE, $source->status);
        $this->assertNull($source->text);
        $this->assertSame('image/jpeg', $source->mime);
    }

    public function test_a_broken_or_empty_pdf_is_marked_failed(): void
    {
        $this->upload([UploadedFile::fake()->createWithContent('broken.pdf', "%PDF-1.4\nnot really a pdf")])->assertCreated();
        $this->assertSame(Source::FAILED, $this->source()->status);

        $this->upload([UploadedFile::fake()->createWithContent('blank.pdf', $this->pdfWithText(''))])->assertCreated();
        $this->assertSame(Source::FAILED, $this->source()->status);
        $this->assertStringContainsString('no readable text', $this->source()->error);
    }

    public function test_dangerous_or_disguised_files_are_rejected(): void
    {
        $this->upload([UploadedFile::fake()->create('shell.php', 5, 'application/x-php')])->assertUnprocessable();
        // A real file on disk, so its type is judged by its content and not by its name.
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, '<?php system($_GET["c"]);');
        $disguised = new UploadedFile($path, 'invoice.pdf', null, null, true);
        $this->upload([$disguised])->assertUnprocessable();
        $this->upload([UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf')])->assertUnprocessable();
        $this->upload([])->assertUnprocessable();

        $this->assertSame(0, Source::where('site_id', $this->siteId)->where('kind', 'file')->count());
    }

    // ---- deleting and ownership -------------------------------------------

    public function test_deleting_a_source_removes_its_file_and_its_facts(): void
    {
        $this->upload([UploadedFile::fake()->createWithContent('menu.pdf', $this->pdfWithText('Hello there'))]);
        $source = $this->source();
        Fact::create(['site_id' => $this->siteId, 'source_id' => $source->id, 'field' => 'phone', 'value' => '1']);

        $this->asOwner($this->token)->deleteJson("/api/sites/{$this->siteId}/sources/{$source->id}")->assertNoContent();

        Storage::disk('local')->assertMissing($source->path);
        $this->assertDatabaseMissing('sources', ['id' => $source->id]);
        $this->assertDatabaseMissing('facts', ['source_id' => $source->id, 'field' => 'phone']);
    }

    public function test_someone_else_cannot_add_read_or_delete_sources(): void
    {
        [, $otherToken] = $this->createSite(['business_name' => 'Other Shop']);
        $this->upload([UploadedFile::fake()->createWithContent('menu.pdf', $this->pdfWithText('Hello'))]);
        $source = $this->source();

        $this->asOwner($otherToken)->postJson("/api/sites/{$this->siteId}/sources", ['kind' => 'note', 'text' => 'hi'])->assertNotFound();
        $this->asOwner($otherToken)->deleteJson("/api/sites/{$this->siteId}/sources/{$source->id}")->assertNotFound();
        $this->postJson("/api/sites/{$this->siteId}/sources", ['kind' => 'note', 'text' => 'hi'])->assertNotFound();
        $this->assertDatabaseHas('sources', ['id' => $source->id]);
    }

    public function test_a_source_from_another_site_cannot_be_deleted_through_this_one(): void
    {
        [$otherId, $otherToken] = $this->createSite(['business_name' => 'Other Shop', 'note' => 'secret']);
        $foreign = Source::where('site_id', $otherId)->firstOrFail();

        $this->asOwner($this->token)->deleteJson("/api/sites/{$this->siteId}/sources/{$foreign->id}")->assertNotFound();
        $this->assertDatabaseHas('sources', ['id' => $foreign->id]);
    }
}
