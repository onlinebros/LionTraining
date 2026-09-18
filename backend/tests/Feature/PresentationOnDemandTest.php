<?php

namespace Tests\Feature;

use App\Models\Presentation;
use App\Models\PresentationAttendee;
use App\Models\Role;
use App\Models\ScreenRecording;
use App\Models\User;
use App\Services\Presentations\AttendeeRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\NotificationService;
use Tests\Support\FakeNotifications;
use Tests\TestCase;

/**
 * A video a member shares openly, which starts from the beginning for whoever
 * opens it.
 *
 * The opposite of a scheduled showing in one respect only: there is no shared
 * clock, so each viewer has their own position and the room never reports one.
 * Everything else — the invite code, the isolation, the chat, the notification
 * when somebody starts watching — is the same machinery, and these tests exist
 * to prove the format did not quietly opt out of any of it.
 */
class PresentationOnDemandTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;
    private User $bob;
    private ScreenRecording $released;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleSeeder::class);
        config([
            'presentations.open_to_members'      => true,
            'presentations.members_can_schedule' => true,
        ]);

        $this->alice = $this->member('Alice');
        $this->bob   = $this->member('Bob');

        $this->released = ScreenRecording::create([
            'title'              => 'Opportunity Talk',
            'disk'               => 'public',
            'path'               => 'recordings/talk.mp4',
            'mime'               => 'video/mp4',
            'duration_seconds'   => 1800,
            'status'             => ScreenRecording::STATUS_READY,
            'member_schedulable' => true,
        ]);
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

    private function share(?User $owner = null): Presentation
    {
        return Presentation::create([
            'title'            => 'Watch this when you can',
            'format'           => Presentation::FORMAT_ON_DEMAND,
            'recording_id'     => $this->released->id,
            'scheduled_at'     => now(),
            'started_at'       => now(),
            'duration_seconds' => 1800,
            'status'           => Presentation::STATUS_LIVE,
            'owner_user_id'    => ($owner ?? $this->alice)->id,
            'created_by'       => ($owner ?? $this->alice)->id,
        ]);
    }

    private function guestOn(Presentation $presentation, string $name = 'Prospect'): PresentationAttendee
    {
        return $presentation->attendees()->create([
            'host_user_id'  => $this->alice->id,
            'name'          => $name,
            'email'         => strtolower($name).'@example.com',
            'token'         => bin2hex(random_bytes(16)),
            'registered_at' => now(),
        ]);
    }

    // ── Creating one ──────────────────────────────────────────────────────────

    public function test_a_member_can_create_a_share_with_no_start_time(): void
    {
        $this->actingAs($this->alice)->post('/member/presentations', [
            'title'        => 'Watch this when you can',
            'recording_id' => $this->released->id,
            'format'       => Presentation::FORMAT_ON_DEMAND,
        ])->assertRedirect();

        $presentation = Presentation::firstWhere('title', 'Watch this when you can');

        $this->assertNotNull($presentation);
        $this->assertTrue($presentation->isOnDemand());
        // Open the moment it exists: there is nothing for the runner to start.
        $this->assertSame(Presentation::STATUS_LIVE, $presentation->status);
        $this->assertNotNull($presentation->started_at);
        $this->assertSame($this->alice->id, $presentation->owner_user_id);
    }

    public function test_a_scheduled_showing_still_demands_a_time(): void
    {
        $this->actingAs($this->alice)->post('/member/presentations', [
            'title'        => 'Team night',
            'recording_id' => $this->released->id,
            'format'       => Presentation::FORMAT_SCHEDULED,
        ])->assertSessionHasErrors('scheduled_at');

        $this->assertDatabaseMissing('presentations', ['title' => 'Team night']);
    }

    public function test_a_share_reports_no_shared_position(): void
    {
        $share = $this->share();

        // Every viewer is somewhere different, so there is no room clock to
        // report and nothing to end when the video's length runs out.
        $this->assertNull($share->currentOffset());
        $this->assertFalse($share->isOverrun());
        $this->assertNull($share->secondsUntilStart());

        $this->artisan('presentations:run');

        $this->assertSame(Presentation::STATUS_LIVE, $share->refresh()->status);
    }

    // ── Watching one ──────────────────────────────────────────────────────────

    public function test_it_still_refuses_a_guest_with_no_invite_code(): void
    {
        $share = $this->share();

        // The rule that makes attribution possible does not bend for a link
        // that is meant to be shared openly — openly still means through a
        // member.
        $this->get('/watch/'.$share->slug)->assertStatus(404);

        $this->get('/watch/'.$share->slug.'/'.$this->alice->referral_code)
            ->assertOk()
            ->assertSee('name="code"', false);
    }

    public function test_the_player_is_told_to_start_from_the_beginning(): void
    {
        $share    = $this->share();
        $attendee = $this->guestOn($share);

        $state = $this->withCredentials()
            ->withCookie('pres_'.$share->id, $attendee->token)
            ->getJson('/watch/'.$share->slug.'/state')
            ->assertOk()
            ->json();

        $this->assertSame(Presentation::FORMAT_ON_DEMAND, $state['format']);
        $this->assertSame(0, $state['resume_at']);
        $this->assertSame(0, $state['furthest']);
        // Nothing is withheld: there is no doors-open moment to wait for.
        $this->assertTrue($state['can_preload']);
    }

    public function test_a_second_sitting_picks_up_where_the_first_stopped(): void
    {
        $share    = $this->share();
        $attendee = $this->guestOn($share);

        app(AttendeeRegistrar::class)->heartbeat($attendee, $share, 420);

        $state = $this->withCredentials()
            ->withCookie('pres_'.$share->id, $attendee->token)
            ->getJson('/watch/'.$share->slug.'/state')
            ->json();

        $this->assertSame(420, $state['resume_at']);
        $this->assertSame(420, $state['furthest']);
    }

    public function test_a_viewer_cannot_claim_to_be_past_the_end(): void
    {
        $share    = $this->share();
        $attendee = $this->guestOn($share);

        // The ceiling is the video's length, not the room's clock — which is
        // the only ceiling a share has.
        app(AttendeeRegistrar::class)->heartbeat($attendee, $share, 99_999);

        $this->assertSame(1800, $attendee->refresh()->furthest_seconds);
        $this->assertSame(1800, $attendee->position_seconds);
    }

    public function test_going_back_over_something_does_not_lower_their_furthest_point(): void
    {
        $share    = $this->share();
        $attendee = $this->guestOn($share);

        $registrar = app(AttendeeRegistrar::class);
        $registrar->heartbeat($attendee, $share, 600);
        $registrar->heartbeat($attendee->refresh(), $share, 120);

        $attendee->refresh();

        // Where they are now, and the furthest they have earned, are two
        // different questions — rewatching must not close the video off.
        $this->assertSame(120, $attendee->position_seconds);
        $this->assertSame(600, $attendee->furthest_seconds);
    }

    // ── Telling the member ────────────────────────────────────────────────────

    public function test_the_inviting_member_is_told_when_somebody_starts_it(): void
    {
        $share    = $this->share();
        $attendee = $this->guestOn($share, 'Casey');
        $notes    = $this->app->instance(NotificationService::class, new FakeNotifications);

        $this->withCookie('pres_'.$share->id, $attendee->token)
            ->post('/watch/'.$share->slug.'/heartbeat', ['position' => 5])
            ->assertOk();

        $notification = $notes->lastFor($this->alice);

        $this->assertNotNull($notification, 'The member was never told.');

        // "Joined your presentation" is the wrong sentence for a link somebody
        // opened on their own at eleven at night.
        $this->assertStringContainsString('Casey', $notification['title']);
        $this->assertStringContainsString('started your video', $notification['title']);
        // Straight to the console, with that person already open.
        $this->assertStringContainsString('guest='.$attendee->id, $notification['url']);
    }

    // ── In the console ────────────────────────────────────────────────────────

    public function test_a_share_never_leaves_the_console(): void
    {
        $share = $this->share();
        $this->guestOn($share);

        // A scheduled call comes and goes from the console around its start
        // time. A share has no start time, so somebody could open it at any
        // hour and the member has to be able to answer them.
        $feed = $this->actingAs($this->alice)
            ->getJson('/member/presentations/live/feed')
            ->assertOk()
            ->json();

        $room = collect($feed['rooms'])->firstWhere('id', $share->id);

        $this->assertNotNull($room);
        $this->assertSame(Presentation::FORMAT_ON_DEMAND, $room['format']);
        $this->assertNull($room['offset']);
    }

    public function test_the_console_reports_how_far_each_viewer_has_got(): void
    {
        $share    = $this->share();
        $attendee = $this->guestOn($share);

        app(AttendeeRegistrar::class)->heartbeat($attendee, $share, 900);

        $feed = $this->actingAs($this->alice)
            ->getJson('/member/presentations/live/feed')
            ->json();

        $row = collect($feed['attendees'])->firstWhere('id', $attendee->id);

        $this->assertSame(900, $row['position']);
        $this->assertSame(900, $row['furthest']);
        $this->assertSame(50, $row['progress']);
    }

    public function test_another_member_sees_neither_the_share_nor_its_viewers(): void
    {
        $share = $this->share();
        $this->guestOn($share);

        $feed = $this->actingAs($this->bob)
            ->getJson('/member/presentations/live/feed')
            ->assertOk()
            ->json();

        // The whole isolation guarantee applies here exactly as it does to a
        // scheduled call: Alice's share is Alice's.
        $this->assertNull(collect($feed['rooms'])->firstWhere('id', $share->id));
        $this->assertSame([], $feed['attendees']);
    }

    public function test_a_member_can_take_their_share_back_down(): void
    {
        $share = $this->share();

        // It is permanently "live", so the running-call guard would otherwise
        // make it impossible to ever cancel.
        $this->actingAs($this->alice)
            ->delete('/member/presentations/'.$share->slug)
            ->assertRedirect(route('member.presentations.index'));

        $this->assertSoftDeleted('presentations', ['id' => $share->id]);
    }
}
