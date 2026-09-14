<?php

namespace Tests\Feature\Registration;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The invitation guard's contract: the general signup is shut on every front
 * door at once — HTML form, form POST and API — while the sponsor-carrying
 * /join/{code} flow stays open, because that is the way in.
 *
 * The failure mode being pinned here is a quiet one. Closing the HTML form and
 * leaving /api/auth/register open looks completely fine in a browser.
 */
class InviteOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function sponsor(): User
    {
        $role = Role::create([
            'name' => Role::FREE_MEMBER,
            'display_name' => 'Free Member',
            'is_admin' => false,
            'level' => 1,
        ]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function signupPayload(array $overrides = []): array
    {
        return $overrides + [
            'name'                  => 'New Partner',
            'email'                 => 'new@example.com',
            'password'              => 'sup3rSecret!',
            'password_confirmation' => 'sup3rSecret!',
        ];
    }

    // ── Closed ───────────────────────────────────────────────────────────────

    public function test_registration_is_invite_only_by_default(): void
    {
        // Not set in the test itself: the point is that a config nobody
        // configured is closed, not open.
        $this->assertTrue(config('registration.invite_only'));
    }

    public function test_the_signup_form_is_replaced_by_the_invitation_notice(): void
    {
        // 200, not 4xx: a deliberate product state, not an error. A 403 here
        // would fill the error log for the whole invitation-only period.
        $this->get(route('register'))
            ->assertOk()
            ->assertViewIs('public.invitation-required')
            ->assertSee('Invitation only');
    }

    public function test_posting_the_signup_form_is_refused_and_creates_nobody(): void
    {
        // 403, not 200: rendering the friendly page at 200 for a rejected
        // write would tell a script it had succeeded.
        $this->post(route('register.post'), $this->signupPayload())->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
        $this->assertGuest();
    }

    public function test_the_api_signup_endpoint_is_refused_too(): void
    {
        $this->postJson('/api/auth/register', $this->signupPayload())
            ->assertForbidden()
            ->assertJsonStructure(['message']);

        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    // ── Still open ───────────────────────────────────────────────────────────

    public function test_an_invitation_link_still_signs_someone_up(): void
    {
        $sponsor = $this->sponsor();

        $this->get(route('join', $sponsor->referral_code))->assertOk();

        $this->post(route('join.post', $sponsor->referral_code), $this->signupPayload())
            ->assertRedirect(route('member.billing.start'));

        $this->assertDatabaseHas('users', [
            'email'      => 'new@example.com',
            'sponsor_id' => $sponsor->id,
        ]);
        $this->assertAuthenticated();
    }

    public function test_an_enrolled_partner_lands_in_the_tree_not_beside_it(): void
    {
        $sponsor = $this->sponsor();

        $this->post(route('join.post', $sponsor->referral_code), $this->signupPayload());

        $user = User::where('email', 'new@example.com')->firstOrFail();

        // Both representations, because the genealogy reads enrollment_path and
        // the structure reads placement_path — a signup that sets only one is
        // the bug this asserts against.
        $this->assertNotNull($user->enrollment_path);
        $this->assertNotNull($user->placement_path);
        $this->assertSame(User::PLACEMENT_PLACED, $user->placement_status);
    }

    // ── Reopening ────────────────────────────────────────────────────────────

    public function test_clearing_the_flag_reopens_the_general_signup(): void
    {
        config(['registration.invite_only' => false]);

        $this->get(route('register'))->assertOk()->assertViewIs('public.auth.register');

        $this->post(route('register.post'), $this->signupPayload())
            ->assertRedirect(route('member.billing.start'));

        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
    }

    // ── The marketing page must agree with the server ────────────────────────

    public function test_the_landing_page_does_not_offer_a_door_that_is_bolted(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('register'), $html);
        $this->assertStringContainsString('invitation', strtolower($html));
    }

    public function test_the_landing_page_offers_signup_again_when_registration_is_open(): void
    {
        config(['registration.invite_only' => false]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee(route('register'));
    }
}
