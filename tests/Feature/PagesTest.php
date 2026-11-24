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

class PagesTest extends TestCase
{
    use CreatesSites;
    use RefreshDatabase;

    private int $id;

    private string $token;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakeDns();
        Storage::fake('local');
        [$this->id, $this->token] = $this->createSite();
        $this->site = Site::find($this->id);
    }

    private function section(string $key, array $content): void
    {
        Section::create(['site_id' => $this->id, 'key' => $key, 'position' => array_search($key, Section::KEYS), 'content' => $content]);
    }

    private function publish(): void
    {
        $this->site->update(['status' => Site::PUBLISHED, 'published_at' => now()]);
    }

    private function page()
    {
        return $this->get("/s/{$this->site->slug}");
    }

    // ---- the owner's pages ------------------------------------------------------

    public function test_the_start_page_and_the_workspace_load(): void
    {
        $this->get('/')->assertOk()->assertSee('x-data="home"', false)->assertSee('Create my website');
        $this->get("/sites/{$this->id}")->assertOk()->assertSee("workspace({$this->id})", false);
    }

    public function test_the_workspace_only_exists_for_numeric_ids(): void
    {
        $this->get('/sites/abc')->assertNotFound();
        $this->get('/sites/1;drop')->assertNotFound();
    }

    public function test_the_workspace_shell_contains_no_site_data(): void
    {
        // The page is an empty shell. The data is fetched with the key, so nobody can read it from here.
        $this->get("/sites/{$this->id}")->assertDontSee($this->token)->assertDontSee('Sharma Bakery');
    }

    // ---- the public page ------------------------------------------------------------

    public function test_a_published_site_is_shown_to_anyone(): void
    {
        $this->section('hero', ['title' => 'Sharma Bakery', 'city' => 'Pune', 'tagline' => 'Fresh bread every morning']);
        $this->section('about', ['paragraphs' => ['We bake cakes in Pune.']]);
        $this->section('services', ['items' => [['name' => 'Plum cake', 'description' => 'Rich and soft']], 'prices' => ['Plum cake 250']]);
        $this->section('contact', ['phone' => '+91 98765 43210', 'email' => 'hello@sharma.in', 'address' => '12 MG Road', 'hours' => 'Mon-Sat 9-9']);
        $this->publish();

        $this->page()->assertOk()
            ->assertSee('Sharma Bakery')
            ->assertSee('Fresh bread every morning')
            ->assertSee('We bake cakes in Pune.')
            ->assertSee('Plum cake')
            ->assertSee('Plum cake 250')
            ->assertSee('12 MG Road')
            ->assertSee('href="tel:+919876543210"', false)
            ->assertSee('href="mailto:hello@sharma.in"', false);
    }

    public function test_a_site_that_is_not_published_is_a_friendly_404(): void
    {
        $this->section('hero', ['title' => 'Sharma Bakery']);

        $this->page()->assertNotFound()->assertSee('We could not find that page')->assertDontSee('Sharma Bakery');
        $this->get('/s/no-such-site')->assertNotFound();
        $this->get('/s/UPPER')->assertNotFound();
    }

    public function test_the_public_page_sends_a_strict_security_policy(): void
    {
        $this->section('hero', ['title' => 'Sharma Bakery']);
        $this->publish();

        $response = $this->page()->assertOk();

        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'none'", $policy);
        $this->assertStringNotContainsString('script-src', $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_text_written_by_the_owner_can_never_run_in_a_visitors_browser(): void
    {
        $this->section('hero', ['title' => '<script>alert(1)</script>', 'tagline' => '"><img src=x onerror=alert(2)>']);
        $this->section('about', ['paragraphs' => ['<b onmouseover=alert(3)>hi</b> </script><script>alert(4)</script>']]);
        $this->section('services', ['items' => [['name' => '"><svg onload=alert(5)>', 'description' => '<iframe src=//evil.test>']], 'prices' => ['<script>x</script>']]);
        $this->section('contact', ['address' => '<script>alert(6)</script>', 'hours' => '</title><script>alert(7)</script>']);
        $this->publish();

        $html = $this->page()->assertOk()->getContent();

        // Everything is printed as harmless text.
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<svg onload', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('<b onmouseover', $html);
        // The only script on the page is the data block for search engines, and it cannot be closed early.
        $this->assertSame(1, substr_count($html, '<script'));
        $this->assertSame(1, substr_count($html, '</script>'));
    }

    public function test_only_safe_links_become_clickable(): void
    {
        $this->section('contact', [
            'phone' => '98765 43210; <b>x</b>',
            'email' => 'not-an-email"><script>',
            'social' => ['javascript:alert(1)', 'data:text/html,hi', 'https://instagram.com/sharma', 'http://facebook.com/sharma'],
        ]);
        $this->publish();

        $html = $this->page()->assertOk()->getContent();

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('data:text/html', $html);
        $this->assertStringNotContainsString('mailto:', $html);
        $this->assertStringContainsString('href="tel:9876543210"', $html);
        $this->assertStringContainsString('href="https://instagram.com/sharma"', $html);
        $this->assertStringContainsString('rel="noopener nofollow noreferrer"', $html);
    }

    public function test_photos_appear_with_text_alternatives(): void
    {
        $this->section('hero', ['title' => 'Sharma Bakery']);
        $this->asOwner($this->token)->post("/api/sites/{$this->id}/files", ['files' => [UploadedFile::fake()->image('shop.jpg')]], ['Accept' => 'application/json'])->assertCreated();
        $photo = Source::where('site_id', $this->id)->latest('id')->first();
        $this->publish();

        $this->page()->assertOk()
            ->assertSee(url("/api/public/{$this->site->slug}/photos/{$photo->id}"), false)
            ->assertSee('alt="Sharma Bakery, photo 1"', false);
    }

    public function test_the_public_page_never_shows_how_the_site_was_made(): void
    {
        $this->section('hero', ['title' => 'Sharma Bakery']);
        $this->asOwner($this->token)->postJson("/api/sites/{$this->id}/sources", ['kind' => 'note', 'text' => 'Private note 98765']);
        $this->publish();

        $this->page()->assertOk()->assertDontSee($this->token)->assertDontSee('Private note 98765');
    }

    public function test_a_page_with_only_a_title_still_renders(): void
    {
        $this->section('hero', ['title' => 'Sharma Bakery']);
        $this->publish();

        $this->page()->assertOk()->assertSee('Sharma Bakery')->assertDontSee('About us')->assertDontSee('Find us');
    }
}
