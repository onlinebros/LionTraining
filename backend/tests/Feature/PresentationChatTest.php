<?php

namespace Tests\Feature;

use App\Models\Presentation;
use App\Models\PresentationAnnouncement;
use App\Models\PresentationAttendee;
use App\Models\PresentationMessage;
use App\Models\Role;
use App\Models\ScreenRecording;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\NotificationService;
use Tests\Support\FakeNotifications;
use Tests\TestCase;

/**
 * The isolated conversation.
 *
 * A guest's thread reaches the guest, the member who invited them, and admins.
 * These tests spend most of their effort proving the fourth case — everyone
 * else — comes back empty or forbidden, because that is the promise the feature
 * is sold on.
 */
class PresentationChatTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;
    private User $bob;
    private User $admin;
    private Presentation $presentation;
    private PresentationAttendee $aliceGuest;
    private PresentationAttendee $bobGuest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleSeeder::class);
        config(['presentations.open_to_members' => true]);

        $this->alice = $this->member('Alice');
        $this->bob   = $this->member('Bob');
        $this->admin = $this->member('Admin', Role::SUPER_ADMIN);

        $recording = ScreenRecording::create([
            'title'            => 'Talk',
            'disk'             => 'public',
            'path'             => 'recordings/talk.mp4',
            'duration_seconds' => 1800,
            'status'           => ScreenRecording::STATUS_READY,
        ]);

        $this->presentation = Presentation::create([
            'title'            => 'Tuesday',
            'recording_id'     => $recording->id,
            'scheduled_at'     => now()->subMinutes(2),
            'started_at'       => now()->subMinutes(2),
            'duration_seconds' => 1800,
            'status'           => Presentation::STATUS_LIVE,
        ]);

        $this->aliceGuest = $this->guest('Alice Guest', 'aguest@example.com', $this->alice);
        $this->bobGuest   = $this->guest('Bob Guest', 'bguest@example.com', $this->bob);
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

    private function guest(string $name, string $email, ?User $host): PresentationAttendee
    {
        return $this->presentation->attendees()->create([
            'host_user_id'  => $host?->id,
            'name'          => $name,
            'email'         => $email,
            'token'         => bin2hex(random_bytes(16)),
            'registered_at' => now(),
        ]);
    }

    /**
     * Act as a registered guest.
     *
     * withCredentials() matters: Laravel's JSON test helpers drop cookies
     * unless it is set, and the guest's identity IS a cookie — without it every
     * chat call comes back 403 and looks like a permissions bug.
     */
    private function asGuest(PresentationAttendee $attendee): static
    {
        return $this->withCredentials()
            ->withCookie('pres_'.$this->presentation->id, $attendee->token);
    }

    // ── The guest's side ──────────────────────────────────────────────────────

    public function test_a_guest_can_ask_a_question_and_see_the_reply(): void
    {
        $this->asGuest($this->aliceGuest)
            ->post("/watch/{$this->presentation->slug}/messages", ['body' => 'How much does it cost?'])
            ->assertCreated();

        $this->actingAs($this->alice)
            ->post("/member/presentations/thread/{$this->aliceGuest->id}",
                ['body' => 'Nothing to start.'])
            ->assertCreated();

        $bodies = collect(
            $this->asGuest($this->aliceGuest)
                ->getJson("/watch/{$this->presentation->slug}/messages")
                ->json('messages')
        )->pluck('body');

        $this->assertTrue($bodies->contains('How much does it cost?'));
        $this->assertTrue($bodies->contains('Nothing to start.'));
    }

    public function test_a_guest_only_ever_sees_their_own_thread(): void
    {
        $this->asGuest($this->aliceGuest)
            ->post("/watch/{$this->presentation->slug}/messages", ['body' => 'Alice question']);
        $this->asGuest($this->bobGuest)
            ->post("/watch/{$this->presentation->slug}/messages", ['body' => 'Bob question']);

        $bodies = collect(
            $this->asGuest($this->aliceGuest)
                ->getJson("/watch/{$this->presentation->slug}/messages")
                ->json('messages')
        )->pluck('body');

        $this->assertTrue($bodies->contains('Alice question'));
        $this->assertFalse($bodies->contains('Bob question'), 'Guests must not see each other.');
    }

    public function test_an_unregistered_visitor_cannot_read_or_post(): void
    {
        $this->getJson("/watch/{$this->presentation->slug}/messages")->assertStatus(403);
        $this->postJson("/watch/{$this->presentation->slug}/messages", ['body' => 'hi'])->assertStatus(403);
    }

    // ── The isolation boundary ────────────────────────────────────────────────

    public function test_a_member_cannot_read_another_members_guest_thread(): void
    {
        $this->asGuest($this->bobGuest)
            ->post("/watch/{$this->presentation->slug}/messages", ['body' => 'Private to Bob']);

        $this->actingAs($this->alice)
            ->getJson("/member/presentations/thread/{$this->bobGuest->id}")
            ->assertNotFound();
    }

    public function test_a_member_cannot_reply_into_another_members_guest_thread(): void
    {
        $this->actingAs($this->alice)
            ->postJson("/member/presentations/thread/{$this->bobGuest->id}",
                ['body' => 'Let me hijack this prospect'])
            ->assertNotFound();

        $this->assertSame(0, PresentationMessage::where('attendee_id', $this->bobGuest->id)->count());
    }

    public function test_a_member_reads_and_answers_their_own_guest(): void
    {
        $this->asGuest($this->aliceGuest)
            ->post("/watch/{$this->presentation->slug}/messages", ['body' => 'Mine']);

        $this->actingAs($this->alice)
            ->getJson("/member/presentations/thread/{$this->aliceGuest->id}")
            ->assertOk()
            ->assertJsonPath('messages.0.body', 'Mine');
    }

    public function test_admins_can_read_and_answer_every_thread(): void
    {
        $this->asGuest($this->bobGuest)
            ->post("/watch/{$this->presentation->slug}/messages", ['body' => 'Bob question']);

        // Through the one console, which is where every conversation now lives.
        // An admin reaches any guest there; a member reaches only their own,
        // and that is the same endpoint enforcing it.
        $this->actingAs($this->admin)
            ->getJson("/member/presentations/thread/{$this->bobGuest->id}")
            ->assertOk()
            ->assertJsonPath('messages.0.body', 'Bob question');

        $this->actingAs($this->admin)
            ->postJson("/member/presentations/thread/{$this->bobGuest->id}",
                ['body' => 'Answering as the host'])
            ->assertCreated();
    }

    // ── One place to chat ─────────────────────────────────────────────────────

    public function test_a_notification_points_at_your_rooms_with_the_person_open(): void
    {
        $notes = $this->app->instance(NotificationService::class, new FakeNotifications);

        $this->asGuest($this->aliceGuest)
            ->post("/watch/{$this->presentation->slug}/messages", ['body' => 'A question']);

        $url = $notes->lastFor($this->alice)['url'];

        // Every route into a conversation goes to the same console. It used to
        // point at the per-showing page, whose reply box posted to a URL that
        // does not exist.
        $this->assertStringContainsString(route('member.presentations.live'), $url);
        $this->assertStringContainsString('guest='.$this->aliceGuest->id, $url);
    }

    public function test_the_console_opens_a_guest_whose_call_finished_long_ago(): void
    {
        $this->presentation->forceFill([
            'status'   => Presentation::STATUS_ENDED,
            'ended_at' => now()->subWeek(),
        ])->save();

        // Outside the window the console normally keeps open, so without the
        // deep link this room would not be in the feed at all — and a
        // notification sent last month has to still open.
        $without = $this->actingAs($this->alice)
            ->getJson('/member/presentations/live/feed')
            ->json('rooms');

        $this->assertSame([], collect($without)->pluck('id')->all());

        $with = $this->actingAs($this->alice)
            ->getJson('/member/presentations/live/feed?guest='.$this->aliceGuest->id)
            ->json();

        $this->assertContains($this->presentation->id, collect($with['rooms'])->pluck('id'));
        $this->assertContains($this->aliceGuest->id, collect($with['attendees'])->pluck('id'));
    }

    public function test_the_deep_link_cannot_be_used_to_pull_in_someone_elses_room(): void
    {
        $this->presentation->forceFill([
            'status'   => Presentation::STATUS_ENDED,
            'ended_at' => now()->subWeek(),
        ])->save();

        // Bob naming Alice's guest must not reach into the room on their
        // behalf. The guest is resolved through visibleTo, so he names nobody.
        $feed = $this->actingAs($this->bob)
            ->getJson('/member/presentations/live/feed?guest='.$this->aliceGuest->id)
            ->json();

        $this->assertSame([], collect($feed['rooms'])->pluck('id')->all());
        $this->assertSame([], $feed['attendees']);
    }

    public function test_the_showing_page_sends_you_to_your_rooms_rather_than_chatting_itself(): void
    {
        $page = $this->actingAs($this->alice)
            ->get("/member/presentations/{$this->presentation->slug}")
            ->assertOk();

        $page->assertSee('Open Your Rooms');
        // No second reply box to fall out of date.
        $page->assertDontSee('id="pane-form"', false);
    }

    // ── Unread counts ─────────────────────────────────────────────────────────

    public function test_the_unread_badge_counts_only_the_members_own_guests(): void
    {
        $this->asGuest($this->aliceGuest)->post("/watch/{$this->presentation->slug}/messages", ['body' => 'q1']);
        $this->asGuest($this->aliceGuest)->post("/watch/{$this->presentation->slug}/messages", ['body' => 'q2']);
        $this->asGuest($this->bobGuest)->post("/watch/{$this->presentation->slug}/messages", ['body' => 'q3']);

        $panel = $this->actingAs($this->alice)
            ->getJson("/member/presentations/{$this->presentation->slug}/attendees")
            ->assertOk()
            ->json('attendees');

        $this->assertCount(1, $panel);
        $this->assertSame(2, $panel[0]['unread']);
    }

    public function test_replying_marks_the_guests_questions_read(): void
    {
        $this->asGuest($this->aliceGuest)->post("/watch/{$this->presentation->slug}/messages", ['body' => 'q']);

        $this->actingAs($this->alice)
            ->post("/member/presentations/thread/{$this->aliceGuest->id}",
                ['body' => 'answered']);

        $panel = $this->actingAs($this->alice)
            ->getJson("/member/presentations/{$this->presentation->slug}/attendees")
            ->json('attendees');

        $this->assertSame(0, $panel[0]['unread']);
    }

    // ── The console feed ──────────────────────────────────────────────────────

    public function test_one_poll_carries_every_conversation_the_member_can_see(): void
    {
        $this->asGuest($this->aliceGuest)->post("/watch/{$this->presentation->slug}/messages", ['body' => 'first']);
        $this->asGuest($this->aliceGuest)->post("/watch/{$this->presentation->slug}/messages", ['body' => 'second']);
        $this->asGuest($this->bobGuest)->post("/watch/{$this->presentation->slug}/messages", ['body' => 'bob only']);

        $feed = $this->actingAs($this->alice)
            ->getJson("/member/presentations/{$this->presentation->slug}/attendees")
            ->assertOk();

        // Every message from Alice's threads arrives in the same response that
        // brings the guest list — that is what makes switching instant.
        $bodies = collect($feed->json('messages'))->pluck('body');

        $this->assertTrue($bodies->contains('first'));
        $this->assertTrue($bodies->contains('second'));
        $this->assertFalse($bodies->contains('bob only'), 'The feed is scoped like everything else.');

        // Each message says which conversation it belongs to, so the client can
        // file it without asking again.
        $this->assertSame($this->aliceGuest->id, $feed->json('messages.0.attendee'));
    }

    public function test_the_feed_only_returns_what_is_new(): void
    {
        $this->asGuest($this->aliceGuest)->post("/watch/{$this->presentation->slug}/messages", ['body' => 'old news']);

        $first = $this->actingAs($this->alice)
            ->getJson("/member/presentations/{$this->presentation->slug}/attendees")
            ->json('messages');

        $lastId = collect($first)->max('id');

        $this->asGuest($this->aliceGuest)->post("/watch/{$this->presentation->slug}/messages", ['body' => 'brand new']);

        $second = $this->actingAs($this->alice)
            ->getJson("/member/presentations/{$this->presentation->slug}/attendees?since={$lastId}")
            ->json('messages');

        $bodies = collect($second)->pluck('body');

        $this->assertTrue($bodies->contains('brand new'));
        $this->assertFalse($bodies->contains('old news'), 'A poll should not re-send what the client holds.');
    }

    public function test_the_list_shows_the_latest_message_as_a_preview(): void
    {
        $this->asGuest($this->aliceGuest)->post("/watch/{$this->presentation->slug}/messages", ['body' => 'earlier']);
        $this->asGuest($this->aliceGuest)->post("/watch/{$this->presentation->slug}/messages", ['body' => 'the newest one']);

        $row = collect(
            $this->actingAs($this->alice)
                ->getJson("/member/presentations/{$this->presentation->slug}/attendees")
                ->json('attendees')
        )->firstWhere('id', $this->aliceGuest->id);

        $this->assertSame('the newest one', $row['preview']);
        $this->assertFalse($row['preview_mine']);
    }

    public function test_reading_a_thread_clears_the_badge_without_replying(): void
    {
        $this->asGuest($this->aliceGuest)->post("/watch/{$this->presentation->slug}/messages", ['body' => 'answered on the phone']);

        $this->actingAs($this->alice)
            ->postJson("/member/presentations/thread/{$this->aliceGuest->id}/read")
            ->assertOk();

        $row = collect(
            $this->actingAs($this->alice)
                ->getJson("/member/presentations/{$this->presentation->slug}/attendees")
                ->json('attendees')
        )->firstWhere('id', $this->aliceGuest->id);

        $this->assertSame(0, $row['unread']);
    }

    public function test_a_member_cannot_mark_another_members_thread_read(): void
    {
        $this->asGuest($this->bobGuest)->post("/watch/{$this->presentation->slug}/messages", ['body' => 'bob only']);

        $this->actingAs($this->alice)
            ->postJson("/member/presentations/thread/{$this->bobGuest->id}/read")
            ->assertNotFound();

        // Still unread for the member it actually belongs to.
        $row = collect(
            $this->actingAs($this->bob)
                ->getJson("/member/presentations/{$this->presentation->slug}/attendees")
                ->json('attendees')
        )->firstWhere('id', $this->bobGuest->id);

        $this->assertSame(1, $row['unread']);
    }

    // ── Announcements ─────────────────────────────────────────────────────────

    public function test_an_announcement_reaches_every_guest_but_is_not_a_thread(): void
    {
        $this->actingAs($this->admin)
            ->post("/admin/presentations/{$this->presentation->slug}/announce",
                ['body' => 'We will cover pricing next.'])
            ->assertRedirect();

        foreach ([$this->aliceGuest, $this->bobGuest] as $guest) {
            $announcements = collect(
                $this->asGuest($guest)
                    ->getJson("/watch/{$this->presentation->slug}/messages")
                    ->json('announcements')
            )->pluck('body');

            $this->assertTrue($announcements->contains('We will cover pricing next.'));
        }

        // It is not a message, so it never lands in anyone's private thread.
        $this->assertSame(0, PresentationMessage::count());
        $this->assertSame(1, PresentationAnnouncement::count());
    }

    public function test_members_cannot_announce(): void
    {
        $this->actingAs($this->alice)
            ->post("/admin/presentations/{$this->presentation->slug}/announce", ['body' => 'hi'])
            ->assertForbidden();
    }
}
