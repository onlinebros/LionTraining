<?php

namespace Tests\Feature\Partner;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A claimed spot registered when it was claimed, not when the import ran.
 *
 * Imports create positions in bulk, days or weeks before anyone claims them,
 * so ordering or dating people by `created_at` buries the newest sign-ups.
 */
class RegisteredDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();

        Role::create(['name' => Role::FREE_MEMBER, 'display_name' => 'Free Member', 'is_admin' => false, 'level' => 1]);
        Role::create(['name' => Role::SUPER_ADMIN, 'display_name' => 'Super Admin', 'is_admin' => true, 'level' => 9]);
    }

    private function user(string $name, \DateTimeInterface $createdAt, ?\DateTimeInterface $claimedAt = null): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->forceFill(['created_at' => $createdAt, 'claimed_at' => $claimedAt])->save();

        return $user->refresh();
    }

    public function test_a_claimed_spot_registers_at_its_claim(): void
    {
        $claimed = $this->user('Claimed Spot', now()->subDays(10), now()->subHour());
        $direct  = $this->user('Direct Signup', now()->subDays(2));

        $this->assertTrue($claimed->registeredAt()->equalTo(now()->subHour()));
        $this->assertTrue($direct->registeredAt()->equalTo(now()->subDays(2)));

        $this->assertSame(
            ['Claimed Spot', 'Direct Signup'],
            User::query()->whereIn('id', [$claimed->id, $direct->id])->latestRegistered()->pluck('name')->all(),
        );
    }

    public function test_the_admin_dashboard_lists_claims_as_recent(): void
    {
        $this->user('Imported Last Week', now()->subDays(7), now()->subHours(17));
        $this->user('Signed Up Two Days Ago', now()->subDays(2));

        $admin = User::factory()->create(['role_id' => Role::findByName(Role::SUPER_ADMIN)->id]);
        $admin->forceFill(['created_at' => now()->subYear()])->save();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Imported Last Week', '17 hours ago', 'Signed Up Two Days Ago', '2 days ago']);
    }
}
