<?php

namespace Tests\Feature;

use App\Models\Presentation;
use App\Models\PresentationAttendee;
use App\Models\PresentationMessage;
use App\Models\Role;
use App\Models\ScreenRecording;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Working several overlapping calls from one console.
 *
 * Two showings can run at once with the same member's guests spread across
 * them. The console has to gather those without ever crossing the boundary it
 * gathers them for — and because threads are now keyed by the guest alone
 * rather than by guest-plus-showing, the scoping is doing more work than
 * before. That is what most of this file is checking.
 */
class PresentationLiveConsoleTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;
    private User $bob;
    private User $admin;
    private Presentation $roomOne;
    private Presentation $roomTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleSeeder::class);
        config(['presentations.open_to_members' => true]);

        $this->alice = $this->member('Alice');
        $this->bob   = $this->member('Bob');
        $this->admin = $this->member('Admin', Role::SUPER_ADMIN);

        $this->roomOne = $this->room('Seven o clock call');
        $this->roomTwo = $this->room('Eight o clock call');
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

    private function room(string $title): Presentation
    {
        $recording = ScreenRecording::create([
            'title'            => $title.' video',
            'disk'             => 'public',
            'path'             => 'recordings/'.uniqid().'.mp4',
            'duration_seconds' => 1800,
            'status'           => ScreenRecording::STATUS_READY,
        ]);

        return Presentation::create([
            'title'            => $title,
            'recording_id'     => $recording->id,
            'scheduled_at'     => now()->subMinutes(3),
            'started_at'       => now()->subMinutes(3),
            'duration_seconds' => 1800,
            'status'           => Presentation::STATUS_LIVE,
        ]);
    }

    private function guest(Presentation $room, string $name, ?User $host): PresentationAttendee
    {
        return $room->attendees()->create([
            'host_user_id'  => $host?->id,
            'name'          => $name,
            'email'         => strtolower(str_replace(' ', '', $name)).'-'.uniqid().'@example.com',
            'token'         => bin2hex(random_bytes(16)),
            'registered_at' => now(),
        ]);
    }

    private function asGuest(PresentationAttendee $a): static
    {
        return $this->withCredentials()
            ->withCookie('pres_'.$a->presentation_id, $a->token);
    }

    private function feed(User $user, ?int $since = null): array
    {
        return $this->actingAs($user)
            ->getJson('/member/presentations/live/feed'.($since ? '?since='.$since : ''))
            ->assertOk()
            ->json();
    }

    // ── Gathering across rooms ────────────────────────────────────────────────

    public function test_the_console_gathers_guests_from_every_running_call(): void
    {
        $this->guest($this->roomOne, 'Sarah Chen', $this->alice);
        $this->guest($this->roomTwo, 'Mike Alvarez', $this->alice);

        $feed = $this->feed($this->alice);

        $this->assertCount(2, $feed['rooms']);
        $this->assertSame(
            ['Mike Alvarez', 'Sarah Chen'],
            collect($feed['attendees'])->pluck('name')->sort()->values()->all(),
        );

        // Each guest says which call they are on — the first thing you need to
        // know before answering them.
        $byName = collect($feed['attendees'])->keyBy('name');
        $this->assertSame($this->roomOne->id, $byName['Sarah Chen']['room']);
        $this->assertSame($this->roomTwo->id, $byName['Mike Alvarez']['room']);
    }

    public function test_messages_from_both_rooms_arrive_in_one_poll(): void
    {
        $one = $this->guest($this->roomOne, 'Sarah', $this->alice);
        $two = $this->guest($this->roomTwo, 'Mike', $this->alice);

        $this->asGuest($one)->post("/watch/{$this->roomOne->slug}/messages", ['body' => 'from room one']);
        $this->asGuest($two)->post("/watch/{$this->roomTwo->slug}/messages", ['body' => 'from room two']);

        $bodies = collect($this->feed($this->alice)['messages'])->pluck('body');

        $this->assertTrue($bodies->contains('from room one'));
        $this->assertTrue($bodies->contains('from room two'));
    }

    public function test_the_feed_only_returns_what_is_new(): void
    {
        $one = $this->guest($this->roomOne, 'Sarah', $this->alice);
        $this->asGuest($one)->post("/watch/{$this->roomOne->slug}/messages", ['body' => 'old']);

        $lastId = collect($this->feed($this->alice)['messages'])->max('id');

        $this->asGuest($one)->post("/watch/{$this->roomOne->slug}/messages", ['body' => 'new']);

        $bodies = collect($this->feed($this->alice, $lastId)['messages'])->pluck('body');

        $this->assertTrue($bodies->contains('new'));
        $this->assertFalse($bodies->contains('old'));
    }

    // ── The boundary still holds ──────────────────────────────────────────────

    public function test_the_console_never_gathers_another_members_guests(): void
    {
        $this->guest($this->roomOne, 'Alice Guest', $this->alice);
        $this->guest($this->roomOne, 'Bob Guest', $this->bob);
        $this->guest($this->roomTwo, 'Bob Second', $this->bob);

        $names = collect($this->feed($this->alice)['attendees'])->pluck('name');

        $this->assertTrue($names->contains('Alice Guest'));
        $this->assertFalse($names->contains('Bob Guest'));
        $this->assertFalse($names->contains('Bob Second'));
    }

    /**
     * The thread endpoints no longer name a showing, so the only thing standing
     * between a member and somebody else's guest is the visibility scope. Prove
     * it by asking for a guest id directly.
     */
    public function test_a_guest_id_alone_does_not_open_another_members_thread(): void
    {
        $bobsGuest = $this->guest($this->roomOne, 'Bob Guest', $this->bob);

        $this->asGuest($bobsGuest)
            ->post("/watch/{$this->roomOne->slug}/messages", ['body' => 'private to Bob']);

        $this->actingAs($this->alice)
            ->getJson("/member/presentations/thread/{$bobsGuest->id}")
            ->assertNotFound();

        $this->actingAs($this->alice)
            ->postJson("/member/presentations/thread/{$bobsGuest->id}", ['body' => 'hijack'])
            ->assertNotFound();

        $this->actingAs($this->alice)
            ->postJson("/member/presentations/thread/{$bobsGuest->id}/read")
            ->assertNotFound();

        $this->assertSame(1, PresentationMessage::where('attendee_id', $bobsGuest->id)->count());
    }

    public function test_a_member_can_answer_their_own_guest_in_either_room(): void
    {
        $one = $this->guest($this->roomOne, 'Sarah', $this->alice);
        $two = $this->guest($this->roomTwo, 'Mike', $this->alice);

        foreach ([$one, $two] as $guest) {
            $this->actingAs($this->alice)
                ->postJson("/member/presentations/thread/{$guest->id}", ['body' => 'answered'])
                ->assertCreated();
        }

        // The reply lands in the right room without the caller naming it.
        $this->assertSame(
            $this->roomOne->id,
            PresentationMessage::where('attendee_id', $one->id)->first()->presentation_id,
        );
        $this->assertSame(
            $this->roomTwo->id,
            PresentationMessage::where('attendee_id', $two->id)->first()->presentation_id,
        );
    }

    // ── A call that starts while the console is open ──────────────────────────

    /**
     * The gap this closes: the console's cursor is already past everything said
     * in a room before that room appeared, so asking only for "newer than X"
     * would show the new call's guests with their conversations missing.
     */
    public function test_a_call_that_opens_later_brings_its_whole_history(): void
    {
        $early = $this->guest($this->roomOne, 'Sarah', $this->alice);
        $later = $this->guest($this->roomTwo, 'Mike', $this->alice);

        // Mike talks while room two is still out of the console's view.
        $this->asGuest($later)->post("/watch/{$this->roomTwo->slug}/messages", ['body' => 'said before it opened']);
        $this->asGuest($early)->post("/watch/{$this->roomOne->slug}/messages", ['body' => 'in room one']);

        // A console that has only ever seen room one, cursor already past both.
        $lastId = PresentationMessage::max('id');

        $feed = $this->actingAs($this->alice)
            ->getJson("/member/presentations/live/feed?since={$lastId}&known={$this->roomOne->id}")
            ->assertOk()
            ->json();

        $bodies = collect($feed['messages'])->pluck('body');

        $this->assertTrue(
            $bodies->contains('said before it opened'),
            'A room appearing mid-session must arrive with its history, not just its future.',
        );
        $this->assertFalse(
            $bodies->contains('in room one'),
            'A room the console already holds should still only send the delta.',
        );
    }

    public function test_a_newly_appeared_room_is_flagged_so_it_can_be_announced(): void
    {
        $this->guest($this->roomTwo, 'Mike', $this->alice);

        $feed = $this->actingAs($this->alice)
            ->getJson("/member/presentations/live/feed?since=1&known={$this->roomOne->id}")
            ->assertOk()
            ->json();

        $byId = collect($feed['rooms'])->keyBy('id');

        $this->assertTrue($byId[$this->roomTwo->id]['is_new']);
        $this->assertFalse($byId[$this->roomOne->id]['is_new']);
    }

    public function test_the_first_poll_of_a_session_is_not_treated_as_new_rooms(): void
    {
        // No cursor yet, so everything is arriving for the first time and there
        // is nothing to announce — the console has only just opened.
        $feed = $this->actingAs($this->alice)
            ->getJson('/member/presentations/live/feed')
            ->assertOk()
            ->json();

        foreach ($feed['rooms'] as $room) {
            $this->assertFalse($room['is_new']);
        }
    }

    // ── Which rooms are open ──────────────────────────────────────────────────

    public function test_a_call_that_just_finished_stays_open_for_the_follow_up(): void
    {
        config(['presentations.console_window_minutes' => 30]);

        $this->guest($this->roomOne, 'Sarah', $this->alice);

        $this->roomOne->forceFill([
            'status'   => Presentation::STATUS_ENDED,
            'ended_at' => now()->subMinutes(5),
        ])->save();

        $titles = collect($this->feed($this->alice)['rooms'])->pluck('title');

        $this->assertTrue(
            $titles->contains('Seven o clock call'),
            'A conversation should not vanish the moment a call ends — that is when the follow-up happens.',
        );
    }

    public function test_a_long_finished_call_drops_out(): void
    {
        config(['presentations.console_window_minutes' => 30]);

        $this->roomOne->forceFill([
            'status'   => Presentation::STATUS_ENDED,
            'ended_at' => now()->subHours(4),
        ])->save();

        $titles = collect($this->feed($this->alice)['rooms'])->pluck('title');

        $this->assertFalse($titles->contains('Seven o clock call'));
    }

    public function test_a_call_about_to_start_is_already_open(): void
    {
        config(['presentations.console_window_minutes' => 30]);

        $this->roomTwo->forceFill([
            'status'       => Presentation::STATUS_SCHEDULED,
            'started_at'   => null,
            'scheduled_at' => now()->addMinutes(10),
        ])->save();

        $titles = collect($this->feed($this->alice)['rooms'])->pluck('title');

        $this->assertTrue($titles->contains('Eight o clock call'));
    }

    public function test_another_members_personal_room_never_appears(): void
    {
        $personal = $this->room('Bobs own night');
        $personal->forceFill(['owner_user_id' => $this->bob->id])->save();

        $this->guest($personal, 'Someone', $this->bob);

        $titles = collect($this->feed($this->alice)['rooms'])->pluck('title');

        $this->assertFalse($titles->contains('Bobs own night'));
    }

    // ── Watching along ────────────────────────────────────────────────────────

    public function test_the_feed_carries_what_the_member_needs_to_follow_the_video(): void
    {
        $guest = $this->guest($this->roomOne, 'Sarah', $this->alice);
        $guest->forceFill(['joined_at_offset' => 180, 'position_seconds' => 240])->save();

        $feed = $this->feed($this->alice);

        $room = collect($feed['rooms'])->firstWhere('id', $this->roomOne->id);
        $this->assertStringContainsString('/video', $room['video']);
        $this->assertNotNull($room['offset']);

        // Where each guest came in — the number that differs between them.
        $row = collect($feed['attendees'])->firstWhere('id', $guest->id);
        $this->assertSame(180, $row['joined_secs']);
        $this->assertSame(240, $row['position']);
    }

    public function test_a_member_can_fetch_the_video_of_a_call_they_can_see(): void
    {
        \Illuminate\Support\Facades\Storage::fake('live-test');
        config(['screen-recordings.disk' => 'live-test']);

        $recording = $this->roomOne->recording;
        $recording->forceFill(['disk' => 'live-test'])->save();
        \Illuminate\Support\Facades\Storage::disk('live-test')->put($recording->path, 'bytes');

        $this->actingAs($this->alice)
            ->get("/member/presentations/{$this->roomOne->slug}/video")
            ->assertOk();
    }

    public function test_a_member_cannot_fetch_the_video_of_another_members_room(): void
    {
        $personal = $this->room('Bobs own night');
        $personal->forceFill(['owner_user_id' => $this->bob->id])->save();

        $this->actingAs($this->alice)
            ->get("/member/presentations/{$personal->slug}/video")
            ->assertNotFound();
    }

    // ── The page ──────────────────────────────────────────────────────────────

    public function test_the_console_page_renders_and_carries_no_guest_data(): void
    {
        $this->guest($this->roomOne, 'Sarah Chen', $this->alice);

        $page = $this->actingAs($this->alice)->get('/member/presentations/live')->assertOk();

        $page->assertSee('Your rooms');
        // Built entirely from the feed, so there is nothing in the markup to leak.
        $page->assertDontSee('Sarah Chen');
    }

    public function test_admins_see_every_room(): void
    {
        $this->guest($this->roomOne, 'Alice Guest', $this->alice);
        $this->guest($this->roomTwo, 'Bob Guest', $this->bob);

        $names = collect($this->feed($this->admin)['attendees'])->pluck('name');

        $this->assertTrue($names->contains('Alice Guest'));
        $this->assertTrue($names->contains('Bob Guest'));
    }
}
