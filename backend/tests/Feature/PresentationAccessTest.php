<?php

namespace Tests\Feature;

use App\Models\Presentation;
use App\Models\Role;
use App\Models\ScreenRecording;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The release gate, and the lock on the video file.
 *
 * Two separate promises:
 *
 *  1. While `presentations.open_to_members` is off, the member side is
 *     admin-only — so the whole thing can be rehearsed before anyone else has
 *     it. Guest pages stay open, because a test guest is an ordinary person
 *     with a link and gating them would make it untestable.
 *
 *  2. The video is not obtainable before the showing starts. Without this a
 *     registered guest could pull the file the day before and watch the whole
 *     thing early, which is the one thing a scheduled showing cannot allow.
 */
class PresentationAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $member;
    private User $admin;
    private Presentation $presentation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleSeeder::class);
        Storage::fake('presentations-test');
        config(['screen-recordings.disk' => 'presentations-test']);

        $this->member = $this->user(Role::PAID_MEMBER);
        $this->admin  = $this->user(Role::SUPER_ADMIN);

        $recording = ScreenRecording::create([
            'title'            => 'Talk',
            'disk'             => 'presentations-test',
            'path'             => 'recordings/talk.mp4',
            'mime'             => 'video/mp4',
            'duration_seconds' => 1800,
            'status'           => ScreenRecording::STATUS_READY,
        ]);

        Storage::disk('presentations-test')->put($recording->path, 'video-bytes');

        $this->presentation = Presentation::create([
            'title'            => 'Tuesday',
            'recording_id'     => $recording->id,
            'scheduled_at'     => now()->addHour(),
            'duration_seconds' => 1800,
            'status'           => Presentation::STATUS_SCHEDULED,
        ]);
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name'     => 'U'.$role,
            'email'    => $role.'-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $user->forceFill(['role_id' => Role::findByName($role)->id, 'is_active' => true])->save();

        // /member sits behind RequireActiveSubscription; exempt accounts pass it.
        $user->forceFill(['billing_exempt' => true])->save();

        return $user->refresh();
    }

    private function registerGuest(): string
    {
        $this->post("/watch/{$this->presentation->slug}/register", [
            'name'  => 'Guest',
            'email' => 'guest@example.com',
            'code'  => $this->member->referral_code,
        ]);

        return $this->presentation->attendees()->firstOrFail()->token;
    }

    // ── The release gate ──────────────────────────────────────────────────────

    public function test_while_closed_a_member_cannot_reach_the_member_pages(): void
    {
        config(['presentations.open_to_members' => false]);

        $this->actingAs($this->member)->get('/member/presentations')->assertForbidden();
        $this->actingAs($this->member)
            ->get("/member/presentations/{$this->presentation->slug}")
            ->assertForbidden();
    }

    public function test_while_closed_an_admin_still_gets_the_full_member_experience(): void
    {
        config(['presentations.open_to_members' => false]);

        $this->actingAs($this->admin)->get('/member/presentations')->assertOk();
        $this->actingAs($this->admin)
            ->get("/member/presentations/{$this->presentation->slug}")
            ->assertOk();
    }

    public function test_opening_the_gate_lets_members_in(): void
    {
        config(['presentations.open_to_members' => true]);

        $this->actingAs($this->member)->get('/member/presentations')->assertOk();
    }

    public function test_guest_pages_are_never_gated(): void
    {
        config(['presentations.open_to_members' => false]);

        $this->get("/watch/{$this->presentation->slug}/{$this->member->referral_code}")->assertOk();
    }

    public function test_the_sidebar_hides_presentations_from_members_while_closed(): void
    {
        config(['presentations.open_to_members' => false]);

        $this->actingAs($this->member)->get('/member/dashboard')
            ->assertOk()
            ->assertDontSee('member/presentations');

        $this->actingAs($this->admin)->get('/member/dashboard')
            ->assertOk()
            ->assertSee('member/presentations');
    }

    // ── The video lock ────────────────────────────────────────────────────────

    public function test_the_video_is_not_served_long_before_the_showing_starts(): void
    {
        $token = $this->registerGuest();   // scheduled an hour out

        $this->withCookie('pres_'.$this->presentation->id, $token)
            ->get("/watch/{$this->presentation->slug}/video")
            ->assertNotFound();
    }

    /**
     * A narrow window before the start so a waiting guest's browser can buffer
     * the opening and playback begins cleanly rather than spinning. Somebody
     * who grabs the file two minutes early gains essentially nothing.
     */
    public function test_the_video_opens_shortly_before_the_start_so_it_can_buffer(): void
    {
        config(['presentations.preload_seconds' => 120]);

        $token = $this->registerGuest();

        $this->presentation->forceFill(['scheduled_at' => now()->addSeconds(90)])->save();

        $this->withCookie('pres_'.$this->presentation->id, $token)
            ->get("/watch/{$this->presentation->slug}/video")
            ->assertOk();
    }

    public function test_the_preload_window_can_be_closed_entirely(): void
    {
        config(['presentations.preload_seconds' => 0]);

        $token = $this->registerGuest();

        $this->presentation->forceFill(['scheduled_at' => now()->addSeconds(30)])->save();

        $this->withCookie('pres_'.$this->presentation->id, $token)
            ->get("/watch/{$this->presentation->slug}/video")
            ->assertNotFound();
    }

    public function test_the_video_is_served_once_it_is_running(): void
    {
        $token = $this->registerGuest();

        $this->presentation->forceFill([
            'status'     => Presentation::STATUS_LIVE,
            'started_at' => now(),
        ])->save();

        $this->withCookie('pres_'.$this->presentation->id, $token)
            ->get("/watch/{$this->presentation->slug}/video")
            ->assertOk();
    }

    public function test_an_unregistered_visitor_cannot_get_the_video(): void
    {
        $this->presentation->forceFill([
            'status'     => Presentation::STATUS_LIVE,
            'started_at' => now(),
        ])->save();

        $this->get("/watch/{$this->presentation->slug}/video")->assertForbidden();
    }

    public function test_a_finished_showing_with_no_replay_stops_serving_the_video(): void
    {
        $token = $this->registerGuest();

        $this->presentation->forceFill([
            'status'            => Presentation::STATUS_ENDED,
            'started_at'        => now()->subHours(2),
            'ended_at'          => now()->subHour(),
            'replay_visibility' => Presentation::REPLAY_NONE,
        ])->save();

        $this->withCookie('pres_'.$this->presentation->id, $token)
            ->get("/watch/{$this->presentation->slug}/video")
            ->assertNotFound();
    }

    public function test_a_finished_showing_with_a_replay_keeps_serving_it(): void
    {
        $token = $this->registerGuest();

        $this->presentation->forceFill([
            'status'            => Presentation::STATUS_ENDED,
            'started_at'        => now()->subHours(2),
            'ended_at'          => now()->subHour(),
            'replay_visibility' => Presentation::REPLAY_ATTENDEES,
        ])->save();

        $this->withCookie('pres_'.$this->presentation->id, $token)
            ->get("/watch/{$this->presentation->slug}/video")
            ->assertOk();
    }
}
