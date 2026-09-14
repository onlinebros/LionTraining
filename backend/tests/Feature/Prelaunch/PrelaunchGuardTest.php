<?php

namespace Tests\Feature\Prelaunch;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The guard's contract: a closed section is closed everywhere — URL, menu and
 * API — and opens again by removing one config key, with no deploy.
 */
class PrelaunchGuardTest extends TestCase
{
    use RefreshDatabase;

    /** Laravel ships assertViewIs but no negative counterpart. */
    private function assertNotComingSoon(TestResponse $response): void
    {
        $original = $response->original;
        $view = $original instanceof \Illuminate\View\View ? $original->name() : null;

        $this->assertNotSame(
            'member.coming-soon',
            $view,
            'Expected the section to be open, but the guard closed it.',
        );
    }

    private function closeCommissions(): void
    {
        config([
            'prelaunch.enabled' => true,
            'prelaunch.closed'  => ['commissions' => ['member.commissions.']],
        ]);
    }

    private function member(array $attributes = []): User
    {
        $role = Role::create([
            'name' => Role::FREE_MEMBER,
            'display_name' => 'Free Member',
            'is_admin' => false,
            'level' => 1,
        ]);

        // Billing-exempt so the subscription gate stays out of the way; card
        // capture is Tests\Feature\Billing\SubscriptionGateTest's concern.
        return User::factory()->create($attributes + ['role_id' => $role->id, 'billing_exempt' => true]);
    }

    private function admin(): User
    {
        $role = Role::create([
            'name' => Role::SUPER_ADMIN,
            'display_name' => 'Super Admin',
            'is_admin' => true,
            'level' => 99,
        ]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_guard_off_leaves_every_section_reachable(): void
    {
        config(['prelaunch.enabled' => false]);

        $this->actingAs($this->member())
            ->get(route('member.commissions.index'))
            ->assertOk();
    }

    public function test_closed_section_renders_coming_soon_for_a_member(): void
    {
        $this->closeCommissions();

        $response = $this->actingAs($this->member())
            ->get(route('member.commissions.index'));

        // 200, not 5xx: this is a deliberate product state, and a 5xx here
        // would light up uptime checks for the whole pre-launch period.
        $response->assertOk()
            ->assertViewIs('member.coming-soon')
            ->assertSee('Commissions');
    }

    public function test_closed_section_returns_403_json_to_an_api_client(): void
    {
        $this->closeCommissions();

        $this->actingAs($this->member())
            ->getJson(route('member.commissions.index'))
            ->assertStatus(403)
            ->assertJsonPath('section', 'commissions');
    }

    public function test_preview_flag_lets_a_member_through(): void
    {
        $this->closeCommissions();

        $response = $this->actingAs($this->member(['prelaunch_preview' => true]))
            ->get(route('member.commissions.index'))
            ->assertOk();

        $this->assertNotComingSoon($response);
    }

    public function test_admins_pass_without_the_flag(): void
    {
        $this->closeCommissions();

        // refresh() so the column default from the migration is loaded — a
        // factory-created model does not carry DB defaults.
        $admin = $this->admin()->refresh();
        $this->assertFalse($admin->prelaunch_preview);
        $this->assertTrue($admin->canPreviewPrelaunch());
    }

    public function test_open_sections_stay_reachable_while_others_are_closed(): void
    {
        $this->closeCommissions();

        // Referrals is the core pre-launch screen and must never be caught by
        // the guard — closing the wrong prefix here is the failure that would
        // shut the campaign down.
        $response = $this->actingAs($this->member())
            ->get(route('member.referrals'))
            ->assertOk();

        $this->assertNotComingSoon($response);
    }

    public function test_removing_a_key_opens_exactly_that_section(): void
    {
        config([
            'prelaunch.enabled' => true,
            'prelaunch.closed'  => [
                'commissions' => ['member.commissions.'],
                'crm'         => ['member.crm.'],
            ],
        ]);

        $member = $this->member();

        $this->actingAs($member)->get(route('member.crm.dashboard'))->assertViewIs('member.coming-soon');

        // Open CRM only — no deploy, no migration.
        config(['prelaunch.closed' => ['commissions' => ['member.commissions.']]]);

        $this->assertNotComingSoon($this->actingAs($member)->get(route('member.crm.dashboard')));
        $this->actingAs($member)->get(route('member.commissions.index'))->assertViewIs('member.coming-soon');
    }

    public function test_exemptions_are_matched_exactly_not_by_prefix(): void
    {
        config([
            'prelaunch.enabled' => true,
            'prelaunch.closed'  => ['commissions' => ['member.commissions.']],
            'prelaunch.open'    => ['member.commissions.index'],
        ]);

        // The exempt route is reachable...
        $this->assertNull(\App\Support\Prelaunch::sectionForRoute('member.commissions.index'));

        // ...but a route that merely starts with the same string is not. This is
        // the property that keeps the guard fail-closed as routes get added.
        $this->assertSame(
            'commissions',
            \App\Support\Prelaunch::sectionForRoute('member.commissions.index.export'),
        );
    }

    public function test_closed_sections_disappear_from_the_sidebar(): void
    {
        $member = $this->member();

        config(['prelaunch.enabled' => false]);
        $this->actingAs($member)->get(route('member.dashboard'))
            ->assertOk()
            ->assertSee('Commissions')
            ->assertSee('My CRM');

        $this->closeCommissions();

        // The menu and the middleware read the same source, so a link can never
        // be left pointing at a section that answers coming-soon.
        $this->actingAs($member)->get(route('member.dashboard'))
            ->assertOk()
            ->assertDontSee('Commissions')
            ->assertSee('Support');
    }

    public function test_unknown_section_is_treated_as_open(): void
    {
        config(['prelaunch.enabled' => true, 'prelaunch.closed' => []]);

        // A typo in a Blade template should fail visibly rather than silently
        // hiding a menu item nobody can then find.
        $this->assertFalse(\App\Support\Prelaunch::closed('typo', $this->member()));
    }
}
