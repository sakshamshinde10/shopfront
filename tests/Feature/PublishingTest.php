<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\Site;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesSites;
use Tests\TestCase;

class PublishingTest extends TestCase
{
    use CreatesSites;
    use RefreshDatabase;

    private int $id;

    private string $token;

    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDns();
        Storage::fake('local');
        [$this->id, $this->token] = $this->createSite();
        $this->slug = Site::find($this->id)->slug;
    }

    private function makeReady(): void
    {
        Section::create(['site_id' => $this->id, 'key' => 'hero', 'position' => 0, 'content' => ['title' => 'Sharma Bakery', 'city' => 'Pune', 'tagline' => 'Fresh bread']]);
        Section::create(['site_id' => $this->id, 'key' => 'contact', 'position' => 3, 'content' => ['phone' => '9876543210']]);
        Site::whereKey($this->id)->update(['status' => Site::READY]);
    }

    private function publish()
    {
        return $this->asOwner($this->token)->postJson("/api/sites/{$this->id}/publish");
    }

    // ---- publishing -----------------------------------------------------------

    public function test_a_ready_site_can_be_published_and_gets_a_public_address(): void
    {
        $this->makeReady();

        $this->publish()
            ->assertOk()
            ->assertJsonPath('data.status', Site::PUBLISHED)
            ->assertJsonPath('data.public_url', url("/s/{$this->slug}"));

        $this->assertNotNull(Site::find($this->id)->published_at);
    }

    public function test_publishing_twice_keeps_the_first_publish_time(): void
    {
        $this->makeReady();
        $this->publish();
        $first = Site::find($this->id)->published_at;
        $this->travel(5)->minutes();

        $this->publish()->assertOk();

        $this->assertEquals($first, Site::find($this->id)->published_at);
    }

    public function test_a_site_that_is_not_finished_cannot_be_published(): void
    {
        foreach ([Site::DRAFT, Site::PROCESSING, Site::NEEDS_INPUT, Site::FAILED] as $status) {
            Site::whereKey($this->id)->update(['status' => $status]);
            $this->publish()->assertUnprocessable();
        }

        // "Ready" but with nothing on it.
        Site::whereKey($this->id)->update(['status' => Site::READY]);
        $this->publish()->assertUnprocessable();
        $this->assertSame(Site::READY, Site::find($this->id)->status);
    }

    public function test_only_the_owner_can_publish_or_take_a_site_down(): void
    {
        $this->makeReady();
        [, $other] = $this->createSite(['business_name' => 'Other Shop']);

        $this->asOwner($other)->postJson("/api/sites/{$this->id}/publish")->assertNotFound();
        $this->postJson("/api/sites/{$this->id}/publish")->assertNotFound();
        $this->asOwner($other)->deleteJson("/api/sites/{$this->id}/publish")->assertNotFound();
        $this->assertSame(Site::READY, Site::find($this->id)->status);
    }

    // ---- the public side ---------------------------------------------------------

    public function test_visitors_see_a_published_site_without_any_token(): void
    {
        $this->makeReady();
        $this->publish();

        $response = $this->getJson("/api/public/{$this->slug}")->assertOk();

        $response->assertJsonPath('data.business_name', 'Sharma Bakery')
            ->assertJsonPath('data.sections.0.key', 'hero')
            ->assertJsonPath('data.sections.1.content.phone', '9876543210');
    }

    public function test_a_site_that_is_not_published_does_not_exist_for_visitors(): void
    {
        $this->makeReady();

        $unpublished = $this->getJson("/api/public/{$this->slug}");
        $unknown = $this->getJson('/api/public/no-such-site');

        $unpublished->assertNotFound();
        $this->assertSame($unknown->json('message'), $unpublished->json('message'));
    }

    public function test_the_public_page_never_shows_anything_about_how_the_site_was_made(): void
    {
        $this->makeReady();
        $this->publish();
        $this->asOwner($this->token)->postJson("/api/sites/{$this->id}/sources", ['kind' => 'note', 'text' => 'Secret internal note 12345']);

        $body = $this->getJson("/api/public/{$this->slug}")->getContent();

        $token = $this->token;
        $this->assertStringNotContainsString($token, $body);
        foreach (['edit_token', 'sources', 'questions', 'facts', 'Secret internal note', 'notice', 'status'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, $leak);
        }
    }

    public function test_taking_a_site_offline_hides_it_but_keeps_the_work(): void
    {
        $this->makeReady();
        $this->publish();

        $this->asOwner($this->token)->deleteJson("/api/sites/{$this->id}/publish")
            ->assertOk()->assertJsonPath('data.status', Site::READY)->assertJsonPath('data.public_url', null);

        $this->getJson("/api/public/{$this->slug}")->assertNotFound();
        $this->assertSame(2, Section::where('site_id', $this->id)->count());

        $this->publish()->assertOk();
        $this->getJson("/api/public/{$this->slug}")->assertOk();
    }

    public function test_slugs_with_odd_characters_never_reach_the_database(): void
    {
        $this->getJson('/api/public/'.rawurlencode("x' OR '1'='1"))->assertNotFound();
        $this->getJson('/api/public/UPPERCASE')->assertNotFound();
        $this->getJson('/api/public/..%2F..%2F.env')->assertNotFound();
    }

    // ---- photos -----------------------------------------------------------------------

    private function upload(UploadedFile $file): Source
    {
        $this->asOwner($this->token)->post("/api/sites/{$this->id}/files", ['files' => [$file]], ['Accept' => 'application/json'])->assertCreated();

        return Source::where('site_id', $this->id)->latest('id')->firstOrFail();
    }

    public function test_photos_of_a_published_site_are_listed_and_served_safely(): void
    {
        $this->makeReady();
        $photo = $this->upload(UploadedFile::fake()->image('shop.jpg', 300, 200));
        $this->publish();

        $url = $this->getJson("/api/public/{$this->slug}")->json('data.photos.0');
        $this->assertSame(url("/api/public/{$this->slug}/photos/{$photo->id}"), $url);

        $response = $this->get("/api/public/{$this->slug}/photos/{$photo->id}")->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_photos_are_private_until_the_site_is_published(): void
    {
        $this->makeReady();
        $photo = $this->upload(UploadedFile::fake()->image('shop.jpg'));

        $this->get("/api/public/{$this->slug}/photos/{$photo->id}")->assertNotFound();
    }

    public function test_only_real_photos_of_this_site_can_be_fetched(): void
    {
        $this->makeReady();
        $pdf = $this->upload(UploadedFile::fake()->createWithContent('menu.pdf', $this->pdfWithText('Menu')));
        $svg = Source::create(['site_id' => $this->id, 'kind' => 'file', 'label' => 'x.svg', 'path' => 'sources/x.svg', 'mime' => 'image/svg+xml', 'status' => 'done']);
        Storage::disk('local')->put('sources/x.svg', '<svg onload="alert(1)"/>');
        $note = Source::create(['site_id' => $this->id, 'kind' => 'note', 'label' => 'n', 'text' => 'secret', 'status' => 'done']);
        $missing = Source::create(['site_id' => $this->id, 'kind' => 'file', 'label' => 'gone.jpg', 'path' => 'sources/gone.jpg', 'mime' => 'image/jpeg', 'status' => 'done']);
        $this->publish();

        // A photo that belongs to a different site, even one that is published.
        [$otherId, $otherToken] = $this->createSite(['business_name' => 'Other Shop']);
        $foreign = Source::create(['site_id' => $otherId, 'kind' => 'file', 'label' => 'o.jpg', 'path' => 'sources/o.jpg', 'mime' => 'image/jpeg', 'status' => 'done']);
        Storage::disk('local')->put('sources/o.jpg', 'x');

        foreach ([$pdf, $svg, $note, $missing, $foreign] as $source) {
            $this->get("/api/public/{$this->slug}/photos/{$source->id}")->assertNotFound();
        }
        $this->get("/api/public/{$this->slug}/photos/abc")->assertNotFound();
        // None of them is advertised on the page either.
        $this->assertCount(1, $this->getJson("/api/public/{$this->slug}")->json('data.photos'));
    }
}
