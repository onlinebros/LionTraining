<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding a member on the admin users list, and signing in as them.
 *
 * Impersonation is a real sign-in, so the cases pinned are who may do it, who
 * may not be borrowed, and that the way back lands on the admin's own account.
 */
class UserSearchAndImpersonationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            [Role::FREE_MEMBER, 'Free Member', false, 1],
            [Role::SUPPORT_ADMIN, 'Support Admin', true, 8],
            [Role::SUPER_ADMIN, 'Super Admin', true, 9],
        ] as [$name, $display, $isAdmin, $level]) {
            Role::create(['name' => $name, 'display_name' => $display, 'is_admin' => $isAdmin, 'level' => $level]);
        }
    }

    private function user(string $role, array $attrs = []): User
    {
        return User::factory()->create(['role_id' => Role::findByName($role)->id] + $attrs);
    }

    public function test_search_matches_name_email_and_id(): void
    {
        $admin = $this->user(Role::SUPER_ADMIN);
        $ada   = $this->user(Role::FREE_MEMBER, ['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
        $bob   = $this->user(Role::FREE_MEMBER, ['name' => 'Bob Stone', 'email' => 'bob@example.test']);

        $this->actingAs($admin)->get(route('admin.users.index', ['q' => 'lovel']))
            ->assertOk()->assertSee('ada@example.test')->assertDontSee('bob@example.test');

        $this->actingAs($admin)->get(route('admin.users.index', ['q' => 'BOB@EXAMPLE']))
            ->assertOk()->assertSee('bob@example.test')->assertDontSee('ada@example.test');

        $this->actingAs($admin)->get(route('admin.users.index', ['q' => (string) $bob->id]))
            ->assertOk()->assertSee('bob@example.test');

        // A wildcard character is searched for, not used as one.
        $this->actingAs($admin)->get(route('admin.users.index', ['q' => '%']))
            ->assertOk()->assertDontSee('ada@example.test')->assertDontSee('bob@example.test');
    }

    public function test_super_admin_can_impersonate_a_member_and_come_back(): void
    {
        $admin  = $this->user(Role::SUPER_ADMIN);
        $member = $this->user(Role::FREE_MEMBER);

        $this->actingAs($admin)->post(route('admin.users.impersonate', $member))
            ->assertRedirect($member->landingRoute());

        $this->assertAuthenticatedAs($member);
        $this->assertSame($admin->id, session(Impersonation::SESSION_KEY));

        $this->post(route('impersonation.stop'))
            ->assertRedirect(route('admin.users.show', $member));

        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session(Impersonation::SESSION_KEY));
    }

    public function test_support_admin_cannot_impersonate(): void
    {
        $support = $this->user(Role::SUPPORT_ADMIN);
        $member  = $this->user(Role::FREE_MEMBER);

        $this->actingAs($support)->post(route('admin.users.impersonate', $member))->assertForbidden();
        $this->assertAuthenticatedAs($support);
    }

    public function test_staff_and_deactivated_accounts_cannot_be_borrowed(): void
    {
        $admin    = $this->user(Role::SUPER_ADMIN);
        $staff    = $this->user(Role::SUPPORT_ADMIN);
        $inactive = $this->user(Role::FREE_MEMBER, ['is_active' => false]);

        foreach ([$staff, $inactive, $admin] as $target) {
            $this->actingAs($admin)->post(route('admin.users.impersonate', $target))
                ->assertSessionHasErrors('error');
            $this->assertAuthenticatedAs($admin);
        }
    }

    public function test_stop_without_an_impersonation_does_not_sign_anyone_in(): void
    {
        $member = $this->user(Role::FREE_MEMBER);

        $this->actingAs($member)->post(route('impersonation.stop'))->assertRedirect();
        $this->assertAuthenticatedAs($member);
    }

    public function test_the_list_offers_impersonate_to_super_admins_only(): void
    {
        $member = $this->user(Role::FREE_MEMBER);

        $this->actingAs($this->user(Role::SUPER_ADMIN))->get(route('admin.users.index'))
            ->assertSee(route('admin.users.impersonate', $member), false);

        $this->actingAs($this->user(Role::SUPPORT_ADMIN))->get(route('admin.users.index'))
            ->assertDontSee(route('admin.users.impersonate', $member), false);
    }
}
