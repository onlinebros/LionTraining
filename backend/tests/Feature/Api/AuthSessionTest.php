<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the SPA auth endpoints rely on the httpOnly session cookie
 * (Sanctum stateful mode) and do not hand out bearer tokens.
 */
class AuthSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        // Make every request look like it came from a configured SPA origin
        // so EnsureFrontendRequestsAreStateful attaches the session middleware.
        $this->withHeaders(['Origin' => 'http://localhost']);
    }

    public function test_login_returns_user_and_no_token_in_response_body(): void
    {
        $user = User::factory()->create([
            'email'    => 'session-user@example.com',
            'password' => 'sup3rSecret!',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'session-user@example.com',
            'password' => 'sup3rSecret!',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['user' => ['id', 'email']])
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('access_token');

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_with_bad_credentials_returns_401(): void
    {
        User::factory()->create([
            'email'    => 'session-user@example.com',
            'password' => 'sup3rSecret!',
        ]);

        $this->postJson('/api/auth/login', [
            'email'    => 'session-user@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401);

        $this->assertGuest();
    }

    public function test_login_rejects_inactive_user(): void
    {
        User::factory()->create([
            'email'     => 'inactive@example.com',
            'password'  => 'sup3rSecret!',
            'is_active' => false,
        ]);

        $this->postJson('/api/auth/login', [
            'email'    => 'inactive@example.com',
            'password' => 'sup3rSecret!',
        ])->assertStatus(403);

        $this->assertGuest();
    }

    public function test_register_logs_user_in_via_session(): void
    {
        // This test is about the session-vs-token contract, not about who is
        // allowed to sign up, so it opens the door explicitly. The invitation
        // guard is covered in Tests\Feature\Registration\InviteOnlyTest.
        config(['registration.invite_only' => false]);

        $response = $this->postJson('/api/auth/register', [
            'name'                  => 'New User',
            'email'                 => 'new@example.com',
            'password'              => 'sup3rSecret!',
            'password_confirmation' => 'sup3rSecret!',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['user' => ['id', 'email']])
            ->assertJsonMissingPath('token');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_logout_returns_ok_and_does_not_leak_a_token(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonMissingPath('token');
    }

    public function test_user_model_does_not_expose_create_token_method(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(
            method_exists($user, 'createToken'),
            'User must not have HasApiTokens — sessions only.'
        );
    }
}
