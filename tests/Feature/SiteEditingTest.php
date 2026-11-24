<?php

namespace Tests\Feature;

use App\Models\Fact;
use App\Models\Question;
use App\Models\Section;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\Support\CreatesSites;
use Tests\Support\FakesGemini;
use Tests\TestCase;

class SiteEditingTest extends TestCase
{
    use CreatesSites;
    use FakesGemini;
    use RefreshDatabase;

    private int $id;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->fakeDns();
        $this->fakeGemini();
        [$this->id, $this->token] = $this->createSite();
    }

    private function seedSection(string $key, array $content, bool $edited = false): Section
    {
        return Section::create([
            'site_id' => $this->id,
            'key' => $key,
            'position' => array_search($key, Section::KEYS, true),
            'content' => $content,
            'edited_by_owner' => $edited,
        ]);
    }

    private function confirmedFact(string $field, string $value): void
    {
        Fact::create(['site_id' => $this->id, 'field' => $field, 'value' => $value, 'status' => Fact::CONFIRMED]);
    }

    private function edit(string $key, array $body)
    {
        return $this->asOwner($this->token)->patchJson("/api/sites/{$this->id}/sections/{$key}", $body);
    }

    private function regenerate(string $key)
    {
        return $this->asOwner($this->token)->postJson("/api/sites/{$this->id}/sections/{$key}/regenerate");
    }

    // ---- editing ---------------------------------------------------------------

    public function test_editing_keeps_the_other_fields_and_marks_the_section_as_the_owners(): void
    {
        $this->seedSection('hero', ['title' => 'Sharma Bakery', 'city' => 'Pune', 'tagline' => null]);

        $this->edit('hero', ['tagline' => '  Fresh every morning  '])
            ->assertOk()
            ->assertJsonPath('data.content.tagline', 'Fresh every morning')
            ->assertJsonPath('data.content.title', 'Sharma Bakery')
            ->assertJsonPath('data.edited_by_owner', true);
    }

    public function test_a_section_that_does_not_exist_yet_can_be_created_by_editing(): void
    {
        $this->edit('services', ['items' => [['name' => 'Plum cake', 'description' => 'Rich and soft']], 'prices' => ['Plum cake 250']])
            ->assertCreated();

        $section = Section::where('site_id', $this->id)->where('key', 'services')->first();
        $this->assertSame(2, $section->position);
        $this->assertSame('Plum cake', $section->content['items'][0]['name']);
    }

    public function test_the_about_text_is_trimmed_and_empty_paragraphs_are_refused(): void
    {
        $this->edit('about', ['paragraphs' => ['  We bake daily.  ', 'Open all week.']])
            ->assertSuccessful()->assertJsonPath('data.content.paragraphs', ['We bake daily.', 'Open all week.']);

        $this->edit('about', ['paragraphs' => ['ok', '']])->assertUnprocessable();
        $this->edit('about', ['paragraphs' => array_fill(0, 7, 'too many')])->assertUnprocessable();
        $this->edit('about', [])->assertUnprocessable();
    }

    public function test_services_are_checked(): void
    {
        $this->edit('services', ['items' => [['description' => 'no name']]])->assertUnprocessable();
        $this->edit('services', ['items' => [['name' => str_repeat('a', 101)]]])->assertUnprocessable();
        $this->edit('services', ['items' => [['name' => 'ok', 'description' => str_repeat('a', 301)]]])->assertUnprocessable();
    }

    public function test_contact_details_are_checked_and_links_must_be_web_links(): void
    {
        $this->edit('contact', ['phone' => 'call me'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->edit('contact', ['email' => 'not-an-email'])->assertUnprocessable();
        $this->edit('contact', ['social' => ['javascript:alert(1)']])->assertUnprocessable();
        $this->edit('contact', ['social' => ['data:text/html,<script>alert(1)</script>']])->assertUnprocessable();

        $this->edit('contact', ['phone' => '+91 98765-43210', 'email' => 'a@b.in', 'social' => ['https://instagram.com/shop']])
            ->assertSuccessful()->assertJsonPath('data.content.phone', '+91 98765-43210');
    }

    public function test_clearing_a_contact_field_removes_it(): void
    {
        $this->seedSection('contact', ['phone' => '9876543210', 'address' => '12 MG Road']);

        $response = $this->edit('contact', ['phone' => null])->assertOk();

        $this->assertSame(['address' => '12 MG Road'], $response->json('data.content'));
    }

    public function test_only_known_fields_are_accepted(): void
    {
        $this->seedSection('hero', ['title' => 'Sharma Bakery', 'tagline' => null]);

        $this->edit('hero', ['tagline' => 'Hi', 'status' => 'published', 'edited_by_owner' => false, 'site_id' => 999, 'owner' => 'me'])
            ->assertOk();

        $content = Section::where('site_id', $this->id)->where('key', 'hero')->first();
        $this->assertSame(['title', 'tagline'], array_keys($content->content));
        $this->assertTrue($content->edited_by_owner);
        $this->assertSame($this->id, $content->site_id);
        $this->assertNotSame(Site::PUBLISHED, Site::find($this->id)->status);
    }

    public function test_html_typed_by_the_owner_is_stored_as_plain_text_not_run(): void
    {
        $this->edit('about', ['paragraphs' => ['<script>alert(1)</script> Hello']])->assertSuccessful();

        $this->assertSame('<script>alert(1)</script> Hello', Section::where('key', 'about')->first()->content['paragraphs'][0]);
    }

    public function test_unknown_sections_and_strangers_are_turned_away(): void
    {
        [, $otherToken] = $this->createSite(['business_name' => 'Other Shop']);

        $this->edit('footer', ['x' => 'y'])->assertNotFound();
        $this->asOwner($otherToken)->patchJson("/api/sites/{$this->id}/sections/hero", ['tagline' => 'Hacked'])->assertNotFound();
        $this->patchJson("/api/sites/{$this->id}/sections/hero", ['tagline' => 'Hacked'])->assertNotFound();
        $this->asOwner($otherToken)->postJson("/api/sites/{$this->id}/sections/hero/regenerate")->assertNotFound();
        $this->assertSame(0, Section::where('site_id', $this->id)->count());
    }

    // ---- regenerating ------------------------------------------------------------

    private function withFacts(): void
    {
        $this->confirmedFact('about', 'We bake cakes in Pune.');
        $this->confirmedFact('service', 'Plum cake');
        $this->confirmedFact('phone', '9876543210');
    }

    public function test_regenerating_a_section_replaces_the_owners_edit_with_fresh_text_from_the_facts(): void
    {
        $this->withFacts();
        $this->seedSection('about', ['paragraphs' => ['My own words.']], edited: true);
        $this->fakeGemini(['write' => ['tagline' => null, 'about' => ['Sharma Bakery bakes cakes in Pune.'], 'services' => []]]);

        $this->regenerate('about')
            ->assertOk()
            ->assertJsonPath('data.content.paragraphs', ['Sharma Bakery bakes cakes in Pune.'])
            ->assertJsonPath('data.edited_by_owner', false);
    }

    public function test_regenerating_one_section_leaves_the_others_alone(): void
    {
        $this->withFacts();
        $this->seedSection('hero', ['title' => 'Mine', 'tagline' => 'Keep me'], edited: true);
        $this->seedSection('about', ['paragraphs' => ['old']]);
        $this->fakeGemini(['write' => ['tagline' => 'New', 'about' => ['We bake cakes in Pune.'], 'services' => []]]);

        $this->regenerate('about')->assertOk();

        $this->assertSame('Keep me', Section::where('key', 'hero')->first()->content['tagline']);
    }

    public function test_a_normal_run_never_overwrites_what_the_owner_edited(): void
    {
        $this->withFacts();
        $this->seedSection('about', ['paragraphs' => ['My own words.']], edited: true);
        $this->fakeGemini(['write' => ['tagline' => null, 'about' => ['Machine words.'], 'services' => []]]);

        $this->asOwner($this->token)->postJson("/api/sites/{$this->id}/generate")->assertStatus(202);

        $this->assertSame(['My own words.'], Section::where('key', 'about')->first()->content['paragraphs']);
    }

    public function test_when_the_ai_is_down_regenerating_changes_nothing_and_says_so(): void
    {
        $this->withFacts();
        $this->seedSection('about', ['paragraphs' => ['My own words.']], edited: true);
        $this->fakeGemini(['status' => 503]);

        $this->regenerate('about')->assertStatus(503)->assertJsonStructure(['message']);

        $section = Section::where('key', 'about')->first();
        $this->assertSame(['My own words.'], $section->content['paragraphs']);
        $this->assertTrue($section->edited_by_owner);
    }

    public function test_the_contact_section_is_rebuilt_from_facts_without_the_ai(): void
    {
        $this->withFacts();
        $this->seedSection('contact', ['phone' => '0000000000'], edited: true);
        $this->fakeGemini(['status' => 503]);

        $this->regenerate('contact')->assertOk()->assertJsonPath('data.content.phone', '9876543210');

        $this->assertSame([], $this->geminiCalls);
    }

    public function test_regenerating_needs_facts_to_write_from(): void
    {
        $this->seedSection('about', ['paragraphs' => ['My own words.']], edited: true);

        $this->regenerate('about')->assertUnprocessable();

        $this->assertSame(['My own words.'], Section::where('key', 'about')->first()->content['paragraphs']);
    }

    public function test_regenerating_waits_while_facts_are_in_doubt_or_the_site_is_busy(): void
    {
        $this->withFacts();
        Question::create(['site_id' => $this->id, 'kind' => Question::CONFLICT, 'field' => 'phone', 'prompt' => 'Which?']);
        $this->regenerate('about')->assertUnprocessable();

        Question::query()->delete();
        Site::whereKey($this->id)->update(['status' => Site::PROCESSING]);
        $this->regenerate('about')->assertStatus(409);
    }
}
