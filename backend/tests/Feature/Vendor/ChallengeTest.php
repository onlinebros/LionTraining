<?php

namespace Tests\Feature\Vendor;

use App\Models\CrmContact;
use App\Models\CrmFollowup;
use App\Models\CrmNote;
use App\Models\User;
use App\Models\VendorLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "Can you keep up?" game beside the product page. What matters here is
 * not the game but the round trip: it keeps the partner's code, it leads back
 * to ordering, and it only exists for products that opt in.
 */
class ChallengeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('vendors.vendors.plasmaguard.enabled', true);
        config()->set('vendors.vendors.plasmaguard.checkout.url', 'https://buy.stripe.com/test_link');

        $this->partner = User::factory()->create(['referral_code' => 'PARTNER1', 'is_active' => true, 'name' => 'Pat Partner']);
    }

    private User $partner;

    private function enter(array $data = [])
    {
        return $this->postJson('/p/PARTNER1/plasmaguard/pro-in-duct/challenge', $data + [
            'first_name' => 'Alex',
            'email'      => 'Alex@Example.com',
        ]);
    }

    public function test_the_challenge_renders_under_the_partners_code(): void
    {
        $this->get('/p/PARTNER1/plasmaguard/pro-in-duct/challenge')
            ->assertOk()
            ->assertSee('Can you keep up with the', false)
            ->assertSee('Pat Partner')
            ->assertSee('q3-challenge.js', false);
    }

    public function test_the_result_leads_back_to_the_same_partners_order_form(): void
    {
        $this->get('/p/PARTNER1/plasmaguard/pro-in-duct/challenge')
            ->assertOk()
            ->assertSee(route('vendor.product', ['PARTNER1', 'plasmaguard', 'pro-in-duct']).'#enquire', false);
    }

    public function test_the_product_page_invites_the_challenge(): void
    {
        $this->get('/p/PARTNER1/plasmaguard/pro-in-duct')
            ->assertOk()
            ->assertSee(route('vendor.challenge', ['PARTNER1', 'plasmaguard', 'pro-in-duct']), false);
    }

    public function test_it_says_it_is_a_game_not_a_result(): void
    {
        $this->get('/p/PARTNER1/plasmaguard/pro-in-duct/challenge')
            ->assertSee('A game, not a lab result');
    }

    public function test_a_product_that_has_not_opted_in_has_no_challenge(): void
    {
        config()->set('vendors.vendors.plasmaguard.products.pro-in-duct.challenge', false);

        $this->get('/p/PARTNER1/plasmaguard/pro-in-duct/challenge')->assertNotFound();
        $this->get('/p/PARTNER1/plasmaguard/pro-in-duct')
            ->assertOk()
            ->assertDontSee('/challenge', false);
    }

    public function test_an_unknown_or_inactive_partner_gets_no_challenge(): void
    {
        $this->get('/p/NOSUCHCODE/plasmaguard/pro-in-duct/challenge')->assertNotFound();

        User::where('referral_code', 'PARTNER1')->update(['is_active' => false]);
        $this->get('/p/PARTNER1/plasmaguard/pro-in-duct/challenge')->assertNotFound();
    }

    // ── Sharing ───────────────────────────────────────────────────────────────

    public function test_a_shared_link_previews_the_challenge_not_the_site_icon(): void
    {
        $this->get('/p/PARTNER1/plasmaguard/pro-in-duct/challenge')
            ->assertSee('<meta property="og:title" content="Can you beat the PlasmaGuard PRO?">', false)
            ->assertSee('assets/images/og/plasmaguard-challenge.png', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false);
    }

    // ── Entrant capture ───────────────────────────────────────────────────────

    public function test_entering_puts_the_challenger_in_the_sharing_partners_crm(): void
    {
        $this->enter()->assertOk()->assertJsonStructure(['token', 'name']);

        $contact = CrmContact::where('owner_id', $this->partner->id)->where('email', 'alex@example.com')->first();
        $this->assertNotNull($contact);
        $this->assertSame('Alex', $contact->first_name);
        $this->assertSame('prospect', $contact->contact_type);
        $this->assertTrue($contact->tags()->where('name', 'PlasmaGuard Challenge')->exists());

        // A task for the partner, so it gets worked rather than just stored.
        $this->assertSame(1, CrmFollowup::where('contact_id', $contact->id)
            ->where('assigned_to', $this->partner->id)->where('status', 'pending')->count());

        $this->get('/p/PARTNER1/plasmaguard/pro-in-duct/challenge')
            ->assertSee('Your name and email go to Pat Partner', false);
    }

    public function test_playing_again_does_not_duplicate_the_contact_or_the_follow_up(): void
    {
        $this->enter();
        $this->enter(['first_name' => 'Alexander']);

        $this->assertSame(1, CrmContact::where('email', 'alex@example.com')->count());
        $this->assertSame(1, CrmFollowup::count());
    }

    public function test_an_existing_contact_is_not_renamed_by_a_visitor(): void
    {
        CrmContact::create([
            'owner_id' => $this->partner->id, 'created_by' => $this->partner->id,
            'first_name' => 'Alexandra', 'last_name' => 'Customer', 'email' => 'alex@example.com',
            'contact_type' => 'customer', 'status' => 'purchased', 'lead_source' => 'referral',
        ]);

        $this->enter(['first_name' => 'Someone Else'])->assertOk();

        $c = CrmContact::where('email', 'alex@example.com')->first();
        $this->assertSame('Alexandra', $c->first_name);
        $this->assertSame('purchased', $c->status);
        $this->assertSame('customer', $c->contact_type);
    }

    public function test_name_and_email_are_required(): void
    {
        $this->enter(['first_name' => ''])->assertStatus(422)->assertJsonValidationErrors('first_name');
        $this->enter(['email' => 'not-an-email'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertSame(0, CrmContact::count());
    }

    public function test_the_honeypot_creates_nothing(): void
    {
        $this->enter(['website_url' => 'http://spam.example'])->assertOk();
        $this->assertSame(0, CrmContact::count());
    }

    public function test_a_finished_round_is_noted_against_the_entrant(): void
    {
        $token = $this->enter()->json('token');

        $this->postJson('/p/PARTNER1/plasmaguard/pro-in-duct/challenge/result', [
            'token' => $token, 'you' => 530, 'pro' => 990, 'you_left' => 60, 'pro_left' => 4,
        ])->assertNoContent();

        $contact = CrmContact::where('email', 'alex@example.com')->first();
        $note = CrmNote::where('contact_id', $contact->id)->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('Wiped 53 germs by hand and left 60 behind', $note->body);
        $this->assertSame(1, $contact->fresh()->custom_data['challenge']['plasmaguard/pro-in-duct']['plays']);
    }

    public function test_a_result_needs_a_genuine_token(): void
    {
        $this->postJson('/p/PARTNER1/plasmaguard/pro-in-duct/challenge/result', [
            'token' => 'forged', 'you' => 0, 'pro' => 0, 'you_left' => 0, 'pro_left' => 0,
        ])->assertStatus(422);

        $this->assertSame(0, CrmNote::count());
    }

    // ── From the game to the order form ───────────────────────────────────────

    private function order(array $data = [])
    {
        return $this->post('/p/PARTNER1/plasmaguard/pro-in-duct', $data + [
            'first_name' => 'Alex', 'last_name' => 'Rivera', 'email' => 'alex@example.com', 'phone' => '555-0199',
        ]);
    }

    public function test_the_order_form_is_prefilled_from_the_challenge(): void
    {
        $this->enter(['first_name' => 'Alex', 'email' => 'nobody@fake.test']);

        $this->get('/p/PARTNER1/plasmaguard/pro-in-duct')
            ->assertOk()
            ->assertSee('value="Alex"', false)
            ->assertSee('value="nobody@fake.test"', false)
            ->assertSee('filled in what you gave us in the challenge', false);
    }

    public function test_no_prefill_without_a_challenge_entry(): void
    {
        $this->get('/p/PARTNER1/plasmaguard/pro-in-duct')
            ->assertOk()
            ->assertDontSee('filled in what you gave us in the challenge', false);
    }

    public function test_a_corrected_email_updates_the_same_prospect_rather_than_adding_one(): void
    {
        $this->enter(['first_name' => 'Al', 'email' => 'nobody@fake.test']);
        $this->order()->assertRedirect();

        // One contact: the challenge's, now carrying the real identity.
        $this->assertSame(1, CrmContact::where('owner_id', $this->partner->id)->count());
        $c = CrmContact::first();
        $this->assertSame('alex@example.com', $c->email);
        $this->assertSame('Alex', $c->first_name);
        $this->assertSame('Rivera', $c->last_name);
        $this->assertSame('555-0199', $c->phone);
        $this->assertSame('interested', $c->status);
        $this->assertTrue($c->tags()->where('name', 'PlasmaGuard Challenge')->exists());

        // The enquiry is linked to it, so a later purchase updates it too.
        $this->assertSame($c->id, VendorLead::first()->crm_contact_id);

        $note = CrmNote::where('contact_id', $c->id)->where('title', 'Moved on to ordering')->first();
        $this->assertStringContainsString('Email corrected from nobody@fake.test to alex@example.com', $note->body);
        $this->assertStringContainsString(VendorLead::first()->public_ref, $note->body);
    }

    public function test_the_same_email_just_moves_the_prospect_on(): void
    {
        $this->enter(['email' => 'alex@example.com']);
        $this->order()->assertRedirect();

        $this->assertSame(1, CrmContact::count());
        $this->assertSame('interested', CrmContact::first()->status);
    }

    public function test_an_email_that_is_already_another_contact_is_linked_not_merged(): void
    {
        $existing = CrmContact::create([
            'owner_id' => $this->partner->id, 'created_by' => $this->partner->id,
            'first_name' => 'Alex', 'email' => 'alex@example.com',
            'contact_type' => 'lead', 'status' => 'contacted', 'lead_source' => 'referral',
        ]);

        $this->enter(['email' => 'nobody@fake.test']);
        $this->order()->assertRedirect();

        $challenge = CrmContact::where('email', 'nobody@fake.test')->first();
        $this->assertNotNull($challenge, 'the challenge contact keeps its own email');
        $this->assertSame($existing->id, VendorLead::first()->crm_contact_id);
        $this->assertStringContainsString("already contact #{$existing->id}",
            CrmNote::where('contact_id', $challenge->id)->where('title', 'Moved on to ordering')->value('body'));
        $this->assertTrue(CrmNote::where('contact_id', $existing->id)->where('title', 'Also took the challenge')->exists());
    }

    public function test_a_pre_existing_contact_is_not_rewritten_by_ordering(): void
    {
        CrmContact::create([
            'owner_id' => $this->partner->id, 'created_by' => $this->partner->id,
            'first_name' => 'Jordan', 'email' => 'jordan@example.com',
            'contact_type' => 'customer', 'status' => 'purchased', 'lead_source' => 'referral',
        ]);

        // A visitor plays under somebody else's email, then orders as themselves.
        $this->enter(['email' => 'jordan@example.com']);
        $this->order()->assertRedirect();

        $jordan = CrmContact::where('email', 'jordan@example.com')->first();
        $this->assertNotNull($jordan);
        $this->assertSame('Jordan', $jordan->first_name);
        $this->assertSame('purchased', $jordan->status);
    }

    public function test_another_partners_link_does_not_see_or_touch_the_entry(): void
    {
        User::factory()->create(['referral_code' => 'PARTNER2', 'is_active' => true]);
        $this->enter(['email' => 'nobody@fake.test']);

        $this->get('/p/PARTNER2/plasmaguard/pro-in-duct')
            ->assertOk()
            ->assertDontSee('nobody@fake.test', false);

        $this->post('/p/PARTNER2/plasmaguard/pro-in-duct', ['first_name' => 'Alex', 'email' => 'alex@example.com'])
            ->assertRedirect();

        $this->assertSame('nobody@fake.test', CrmContact::where('owner_id', $this->partner->id)->value('email'));
    }
}
