<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Following a link while logged out should still get you to the link.
 *
 * Notifications deep-link into the back office — straight to the person who
 * just asked a question. Landing on the dashboard instead means hunting for
 * them, which wastes the one moment the notification existed for.
 */
class LoginReturnsYouWhereYouWereGoingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleSeeder::class);
        config(['presentations.open_to_members' => true]);
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name'     => 'Someone',
            'email'    => uniqid().'@example.com',
            'password' => 'password',
        ]);

        $user->forceFill(['role_id' => Role::findByName($role)->id, 'is_active' => true])->save();

        // /member sits behind RequireActiveSubscription; exempt accounts pass it.
        $user->forceFill(['billing_exempt' => true])->save();

        return $user->refresh();
    }

    public function test_a_member_lands_on_the_page_they_asked_for(): void
    {
        $user = $this->user(Role::PAID_MEMBER);

        // The bounce that stores where they were going.
        $this->get('/member/presentations/live?guest=7')->assertRedirect();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/member/presentations/live?guest=7');
    }

    public function test_an_admin_lands_on_the_page_they_asked_for_too(): void
    {
        $user = $this->user(Role::SUPER_ADMIN);

        $this->get('/member/presentations/live?guest=7')->assertRedirect();

        // Admins were sent to the admin dashboard unconditionally, which threw
        // away the destination — and admins are the people most likely to be
        // following one of these links.
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/member/presentations/live?guest=7');
    }

    public function test_an_admin_signing_in_with_nowhere_to_go_still_gets_the_admin_panel(): void
    {
        $user = $this->user(Role::SUPER_ADMIN);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_a_member_signing_in_with_nowhere_to_go_gets_their_dashboard(): void
    {
        $user = $this->user(Role::PAID_MEMBER);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('member.dashboard'));
    }
}
