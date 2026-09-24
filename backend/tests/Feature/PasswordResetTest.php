<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * "Forgot password?" on the sign-in page was a dead link. It now emails a
 * one-time link, and only to accounts that can actually sign in.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $status = User::ACCOUNT_ACTIVE): User
    {
        $user = User::create([
            'name'     => 'Someone',
            'email'    => uniqid().'@example.com',
            'password' => 'old-password',
        ]);

        $user->forceFill(['account_status' => $status])->save();

        return $user->refresh();
    }

    public function test_the_sign_in_page_links_to_the_reset_form(): void
    {
        $this->get('/login')->assertOk()->assertSee(route('password.request'), false);
        $this->get('/forgot-password')->assertOk()->assertSee('Reset your password');
    }

    public function test_an_active_account_gets_a_link_and_can_set_a_new_password(): void
    {
        Notification::fake();
        $user = $this->user();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertSee($user->email);

        $this->post('/reset-password', [
            'token'                 => $token,
            'email'                 => $user->email,
            'password'              => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Auth::validate(['email' => $user->email, 'password' => 'new-password-123']));
        $this->assertFalse(Auth::validate(['email' => $user->email, 'password' => 'old-password']));
    }

    public function test_an_unknown_address_gets_the_same_answer_and_no_email(): void
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'nobody@example.com'])
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_a_holding_spot_cannot_be_reset_around_its_activation_code(): void
    {
        Notification::fake();
        $user = $this->user(User::ACCOUNT_HOLDING);

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');

        Notification::assertNothingSent();
    }

    public function test_a_bad_token_changes_nothing(): void
    {
        $user = $this->user();

        $this->post('/reset-password', [
            'token'                 => 'not-a-real-token',
            'email'                 => $user->email,
            'password'              => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Auth::validate(['email' => $user->email, 'password' => 'old-password']));
    }
}
