<?php

namespace Tests\Feature;

use App\Models\Presentation;
use App\Models\Role;
use App\Models\ScreenRecording;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Members running their own showings for their own team.
 *
 * Two guarantees carry the weight here: a member may only schedule a recording
 * an admin has released for the purpose, and a personal showing is invisible to
 * every other member — as private as the guest list inside it.
 */
class MemberScheduledPresentationTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;
    private User $bob;
    private User $admin;
    private ScreenRecording $released;
    private ScreenRecording $internal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleSeeder::class);
        config([
            'presentations.open_to_members'    => true,
            'presentations.members_can_schedule' => true,
        ]);

        $this->alice = $this->member('Alice');
        $this->bob   = $this->member('Bob');
        $this->admin = $this->member('Admin', Role::SUPER_ADMIN);

        $this->released = $this->recording('Opportunity Talk', schedulable: true);
        $this->internal = $this->recording('Internal Training', schedulable: false);
    }

    private function member(string $name, string $role = Role::PAID_MEMBER): User
    {
        $user = User::create([
            'name'     => $name,
            'email'    => strtolower($name).'-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $user->forceFill(['role_id' => Role::findByName($role)->id, 'is_active' => true])->save();

        // /member sits behind RequireActiveSubscription; exempt accounts pass it.
        $user->forceFill(['billing_exempt' => true])->save();

        return $user->refresh();
    }

    private function recording(string $title, bool $schedulable): ScreenRecording
    {
        return ScreenRecording::create([
            'title'              => $title,
            'disk'               => 'public',
            'path'               => 'recordings/'.uniqid().'.mp4',
            'mime'               => 'video/mp4',
            'duration_seconds'   => 1800,
            'status'             => ScreenRecording::STATUS_READY,
            'member_schedulable' => $schedulable,
        ]);
    }

    private function schedule(User $user, ScreenRecording $recording, string $title = 'My team night')
    {
        return $this->actingAs($user)->post('/member/presentations', [
            'title'        => $title,
            'recording_id' => $recording->id,
            'scheduled_at' => now(Presentation::bookingTimezone())->addDay()->format('Y-m-d\TH:i'),
        ]);
    }

    // ── Creating ──────────────────────────────────────────────────────────────

    public function test_a_member_can_schedule_a_released_recording_and_gets_their_own_link(): void
    {
        $this->actingAs($this->alice)->get('/member/presentations/create')
            ->assertOk()
            ->assertSee('Opportunity Talk')
            ->assertDontSee('Internal Training');

        $this->schedule($this->alice, $this->released)->assertRedirect();

        $showing = Presentation::where('title', 'My team night')->firstOrFail();

        $this->assertSame($this->alice->id, $showing->owner_user_id);
        $this->assertTrue($showing->isPersonal());
        $this->assertSame(1800, $showing->duration_seconds);

        // The link is theirs, carrying their referral code.
        $this->actingAs($this->alice)->get("/member/presentations/{$showing->slug}")
            ->assertOk()
            ->assertSee($this->alice->referral_code, false);
    }

    public function test_a_member_cannot_schedule_a_recording_that_was_not_released(): void
    {
        $this->schedule($this->alice, $this->internal)->assertSessionHasErrors('recording_id');

        $this->assertSame(0, Presentation::count());
    }

    public function test_a_showing_cannot_be_scheduled_in_the_past(): void
    {
        $this->actingAs($this->alice)->post('/member/presentations', [
            'title'        => 'Yesterday',
            'recording_id' => $this->released->id,
            'scheduled_at' => now(Presentation::bookingTimezone())->subDay()->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors('scheduled_at');

        $this->assertSame(0, Presentation::count());
    }

    public function test_the_time_is_read_as_eastern(): void
    {
        $this->actingAs($this->alice)->post('/member/presentations', [
            'title'        => 'Evening',
            'recording_id' => $this->released->id,
            'scheduled_at' => '2026-12-15T19:00',
        ])->assertRedirect();

        $showing = Presentation::where('title', 'Evening')->firstOrFail();

        $this->assertSame('7:00pm', $showing->scheduledAtLocal()->format('g:ia'));
        // December is standard time, so Eastern is UTC-5.
        $this->assertSame('2026-12-16 00:00:00', $showing->scheduled_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_a_member_is_capped_on_how_many_they_can_have_coming_up(): void
    {
        config(['presentations.member_upcoming_limit' => 2]);

        $this->schedule($this->alice, $this->released, 'One')->assertRedirect();
        $this->schedule($this->alice, $this->released, 'Two')->assertRedirect();
        $this->schedule($this->alice, $this->released, 'Three')->assertSessionHas('error');

        $this->assertSame(2, Presentation::where('owner_user_id', $this->alice->id)->count());
    }

    public function test_members_cannot_schedule_when_the_setting_is_off(): void
    {
        config(['presentations.members_can_schedule' => false]);

        $this->actingAs($this->alice)->get('/member/presentations/create')->assertForbidden();
        $this->schedule($this->alice, $this->released)->assertForbidden();
    }

    // ── Privacy ───────────────────────────────────────────────────────────────

    public function test_one_members_showing_is_invisible_to_another(): void
    {
        $this->schedule($this->alice, $this->released, 'Alice night')->assertRedirect();
        $showing = Presentation::where('title', 'Alice night')->firstOrFail();

        // Not in Bob's list…
        $this->actingAs($this->bob)->get('/member/presentations')
            ->assertOk()
            ->assertDontSee('Alice night');

        // …and not reachable by guessing the slug. 404, not 403, so its
        // existence is not confirmed either.
        $this->actingAs($this->bob)->get("/member/presentations/{$showing->slug}")->assertNotFound();
        $this->actingAs($this->bob)
            ->getJson("/member/presentations/{$showing->slug}/attendees")
            ->assertNotFound();
    }

    public function test_another_member_cannot_cancel_it(): void
    {
        $this->schedule($this->alice, $this->released, 'Alice night')->assertRedirect();
        $showing = Presentation::where('title', 'Alice night')->firstOrFail();

        $this->actingAs($this->bob)
            ->delete("/member/presentations/{$showing->slug}")
            ->assertForbidden();

        $this->assertNotNull(Presentation::find($showing->id));
    }

    public function test_the_owner_can_cancel_their_own(): void
    {
        $this->schedule($this->alice, $this->released, 'Alice night')->assertRedirect();
        $showing = Presentation::where('title', 'Alice night')->firstOrFail();

        $this->actingAs($this->alice)
            ->delete("/member/presentations/{$showing->slug}")
            ->assertRedirect(route('member.presentations.index'));

        $this->assertNull(Presentation::find($showing->id));
    }

    public function test_a_company_showing_stays_visible_to_everybody(): void
    {
        $company = Presentation::create([
            'title'            => 'Company wide',
            'recording_id'     => $this->released->id,
            'scheduled_at'     => now()->addDay(),
            'duration_seconds' => 1800,
        ]);

        foreach ([$this->alice, $this->bob] as $member) {
            $this->actingAs($member)->get('/member/presentations')
                ->assertOk()
                ->assertSee('Company wide');

            $this->actingAs($member)->get("/member/presentations/{$company->slug}")->assertOk();
        }

        $this->assertFalse($company->isPersonal());
    }

    public function test_a_member_cannot_cancel_a_company_showing(): void
    {
        $company = Presentation::create([
            'title'            => 'Company wide',
            'recording_id'     => $this->released->id,
            'scheduled_at'     => now()->addDay(),
            'duration_seconds' => 1800,
        ]);

        $this->actingAs($this->alice)
            ->delete("/member/presentations/{$company->slug}")
            ->assertForbidden();

        $this->assertNotNull(Presentation::find($company->id));
    }

    // ── Admins ────────────────────────────────────────────────────────────────

    public function test_an_admin_sees_every_members_showing(): void
    {
        $this->schedule($this->alice, $this->released, 'Alice night')->assertRedirect();
        $showing = Presentation::where('title', 'Alice night')->firstOrFail();

        $this->actingAs($this->admin)->get("/member/presentations/{$showing->slug}")->assertOk();
        $this->actingAs($this->admin)->get("/admin/presentations/{$showing->slug}")->assertOk();
    }

    public function test_the_admin_toggle_releases_a_recording_to_members(): void
    {
        $this->assertFalse($this->internal->member_schedulable);

        $this->actingAs($this->admin)
            ->put("/admin/screen-recordings/{$this->internal->uuid}", [
                'title'              => 'Internal Training',
                'visibility'         => ScreenRecording::VISIBILITY_ADMINS,
                'member_schedulable' => '1',
            ])
            ->assertRedirect();

        $this->assertTrue($this->internal->refresh()->member_schedulable);

        $this->actingAs($this->alice)->get('/member/presentations/create')
            ->assertOk()
            ->assertSee('Internal Training');
    }
}
