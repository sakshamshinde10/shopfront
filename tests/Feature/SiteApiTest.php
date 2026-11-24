<?php

namespace Tests\Feature;

use App\Models\Fact;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesSites;
use Tests\TestCase;

class SiteApiTest extends TestCase
{
    use CreatesSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDns();
        Http::fake(['*' => Http::response('', 404)]);
    }

    public function test_it_creates_a_site_and_returns_the_edit_token_once(): void
    {
        $response = $this->postJson('/api/sites', [
            'business_name' => '  Sharma Bakery ',
            'city' => 'Pune',
            'note' => 'Open 8am to 9pm, closed on Mondays.',
        ])->assertCreated();

        $response->assertJsonPath('site.business_name', 'Sharma Bakery')
            ->assertJsonPath('site.slug', 'sharma-bakery-pune')
            ->assertJsonPath('site.status', Site::DRAFT);
        $this->assertSame(64, strlen($response->json('edit_token')));
        $this->assertArrayNotHasKey('edit_token', $response->json('site'));
    }

    public function test_the_owners_own_details_are_saved_as_confirmed_facts(): void
    {
        [$id] = $this->createSite(['links' => ['https://www.instagram.com/sharmabakery']]);

        $facts = Fact::where('site_id', $id)->get();
        $this->assertSame('Sharma Bakery', $facts->firstWhere('field', 'name')->value);
        $this->assertSame('Pune', $facts->firstWhere('field', 'city')->value);
        $this->assertSame('https://www.instagram.com/sharmabakery', $facts->firstWhere('field', 'social')->value);
        $this->assertTrue($facts->every(fn ($fact) => $fact->status === Fact::CONFIRMED));
    }

    public function test_two_businesses_with_the_same_name_get_different_addresses(): void
    {
        [$first] = $this->createSite();
        [$second] = $this->createSite();

        $slugs = Site::whereIn('id', [$first, $second])->pluck('slug');
        $this->assertCount(2, $slugs->unique());
    }

    public function test_it_rejects_bad_input(): void
    {
        $this->postJson('/api/sites', [])->assertUnprocessable()->assertJsonValidationErrors('business_name');
        $this->postJson('/api/sites', ['business_name' => 'X', 'links' => ['ftp://files.example.com']])
            ->assertUnprocessable()->assertJsonValidationErrors('links.0');
        $this->postJson('/api/sites', ['business_name' => 'X', 'links' => ['javascript:alert(1)']])
            ->assertUnprocessable()->assertJsonValidationErrors('links.0');
        $this->postJson('/api/sites', ['business_name' => str_repeat('a', 151)])
            ->assertUnprocessable()->assertJsonValidationErrors('business_name');
    }

    public function test_a_site_can_only_be_read_with_its_own_token(): void
    {
        [$id, $token] = $this->createSite();
        [, $otherToken] = $this->createSite();

        $this->getJson("/api/sites/{$id}")->assertNotFound();
        $this->asOwner('wrong-token')->getJson("/api/sites/{$id}")->assertNotFound();
        $this->asOwner($otherToken)->getJson("/api/sites/{$id}")->assertNotFound();
        $this->asOwner($token)->getJson("/api/sites/{$id}")->assertOk()->assertJsonPath('data.id', $id);
    }

    public function test_an_unknown_site_looks_the_same_as_a_wrong_token(): void
    {
        $unknown = $this->asOwner('anything')->getJson('/api/sites/999999');
        [$id] = $this->createSite();
        $wrong = $this->asOwner('anything')->getJson("/api/sites/{$id}");

        $this->assertSame($unknown->status(), $wrong->status());
        $this->assertSame($unknown->json('message'), $wrong->json('message'));
    }
}
