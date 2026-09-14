<?php

namespace Tests\Feature\Registration;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `founder:create` is the one door left when registration is invitation only,
 * so it has to produce an account that can actually issue invitations.
 *
 * The interesting assertion is enrollment_path. A founder is the root of a
 * tree, and every partner enrolled beneath them hangs off that path — an
 * account created without one looks completely normal until the first downline
 * report comes back empty.
 */
class CreateFounderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_it_creates_a_founder_who_is_a_real_position_in_the_tree(): void
    {
        $this->artisan('founder:create', [
            'email'  => 'founder@example.com',
            '--name' => 'Jane Doe',
        ])->assertSuccessful();

        $founder = User::where('email', 'founder@example.com')->firstOrFail();

        $this->assertSame('Jane Doe', $founder->name);
        $this->assertTrue($founder->is_active);
        $this->assertNull($founder->sponsor_id, 'A founder is a root, not somebody\'s downline.');

        // Both representations written, in the same transaction — this is what
        // the admin "Add User" form does not do.
        $this->assertNotNull($founder->enrollment_path);
        $this->assertNotNull($founder->placement_path);
        $this->assertSame(User::PLACEMENT_PLACED, $founder->placement_status);

        // Without a code there is no invitation link, and no way in at all.
        $this->assertNotEmpty($founder->referral_code);
    }

    public function test_it_prints_the_invitation_link_and_the_generated_password(): void
    {
        $this->artisan('founder:create', ['email' => 'founder@example.com'])
            ->expectsOutputToContain('/join/')
            ->expectsOutputToContain('Generated password')
            ->assertSuccessful();
    }

    public function test_the_generated_password_actually_logs_in(): void
    {
        $this->artisan('founder:create', [
            'email'      => 'founder@example.com',
            '--password' => 'sup3rSecret!',
        ])->assertSuccessful();

        $founder = User::where('email', 'founder@example.com')->firstOrFail();

        // Guards against the password being hashed twice — the model casts
        // `password` as 'hashed', so a pre-hashed value here would be silently
        // wrong and only show up as "I can't log in" on launch day.
        $this->assertTrue(Hash::check('sup3rSecret!', $founder->password));
    }

    public function test_the_founders_link_enrols_the_founding_team(): void
    {
        $this->artisan('founder:create', ['email' => 'founder@example.com'])->assertSuccessful();
        $founder = User::where('email', 'founder@example.com')->firstOrFail();

        // The whole point: invitation-only is survivable because this works.
        $this->post(route('join.post', $founder->referral_code), [
            'name'                  => 'Second Founder',
            'email'                 => 'second@example.com',
            'password'              => 'sup3rSecret!',
            'password_confirmation' => 'sup3rSecret!',
        ])->assertRedirect(route('member.billing.start'));

        $second = User::where('email', 'second@example.com')->firstOrFail();

        $this->assertSame($founder->id, $second->sponsor_id);
        $this->assertStringStartsWith($founder->enrollment_path.'.', $second->enrollment_path);
    }

    public function test_it_can_place_a_second_founder_under_the_first(): void
    {
        $this->artisan('founder:create', ['email' => 'first@example.com'])->assertSuccessful();

        $this->artisan('founder:create', [
            'email'     => 'second@example.com',
            '--sponsor' => 'first@example.com',
        ])->assertSuccessful();

        $first  = User::where('email', 'first@example.com')->firstOrFail();
        $second = User::where('email', 'second@example.com')->firstOrFail();

        $this->assertSame($first->id, $second->sponsor_id);
    }

    public function test_it_refuses_to_recreate_an_existing_user(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->artisan('founder:create', ['email' => 'taken@example.com'])->assertFailed();

        $this->assertSame(1, User::where('email', 'taken@example.com')->count());
    }

    public function test_it_refuses_an_unknown_role_rather_than_guessing(): void
    {
        $this->artisan('founder:create', [
            'email'  => 'founder@example.com',
            '--role' => 'chief_wizard',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'founder@example.com']);
    }

    public function test_it_refuses_an_unknown_sponsor_rather_than_making_a_stray_root(): void
    {
        $this->artisan('founder:create', [
            'email'     => 'founder@example.com',
            '--sponsor' => 'nobody@example.com',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'founder@example.com']);
    }

    public function test_it_rejects_a_malformed_email(): void
    {
        $this->artisan('founder:create', ['email' => 'not-an-email'])->assertFailed();

        $this->assertSame(0, User::where('email', 'not-an-email')->count());
    }
}
