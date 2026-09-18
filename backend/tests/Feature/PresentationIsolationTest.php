<?php

namespace Tests\Feature;

use App\Models\Presentation;
use App\Models\PresentationAttendee;
use App\Models\Role;
use App\Models\ScreenRecording;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The guarantee the whole feature is built around.
 *
 *   A guest belongs to the member who invited them, and to nobody else.
 *
 * These tests exist to be hostile: they take one member's guest and try to
 * reach them as another member, by every route that returns attendee data.
 * If a future change adds an endpoint that skips `visibleTo()`, the intent is
 * that it shows up here as a failure rather than in production as a member
 * reading another team's prospect list.
 *
 * Do not delete these. If one starts failing, the leak is real.
 */
class PresentationIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;      // member A
    private User $bob;        // member B
    private User $admin;
    private Presentation $presentation;
    private PresentationAttendee $aliceGuest;
    private PresentationAttendee $bobGuest;
    private PresentationAttendee $orphanGuest;   // arrived on the company link

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleSeeder::class);

        // The member side is gated until release; these tests exercise the
        // released behaviour. PresentationAccessTest covers the gate itself.
        config(['presentations.open_to_members' => true]);


        $this->alice = $this->member('alice');
        $this->bob   = $this->member('bob');
        $this->admin = $this->member('admin', Role::SUPER_ADMIN);

        $this->presentation = $this->presentation();

        $this->aliceGuest  = $this->guest('Alice Guest', 'aguest@example.com', $this->alice);
        $this->bobGuest    = $this->guest('Bob Guest', 'bguest@example.com', $this->bob);
        $this->orphanGuest = $this->guest('Nobody Sent Me', 'orphan@example.com', null);
    }

    private function member(string $name, string $role = Role::PAID_MEMBER): User
    {
        $user = User::create([
            'name'     => ucfirst($name),
            'email'    => $name.'-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $user->forceFill([
            'role_id'   => Role::findByName($role)->id,
            'is_active' => true,
        ])->save();

        // The whole /member area sits behind RequireActiveSubscription. In
        // production the pre-launch guard waives that; here the real middleware
        // stack runs, so the member needs a live membership to be tested
        // against the routes members will actually use.
        // /member sits behind RequireActiveSubscription; exempt accounts pass it.
        $user->forceFill(['billing_exempt' => true])->save();

        return $user->refresh();
    }

    private function presentation(): Presentation
    {
        $recording = ScreenRecording::create([
            'title'            => 'Opportunity Talk',
            'disk'             => 'public',
            'path'             => 'recordings/talk.mp4',
            'mime'             => 'video/mp4',
            'duration_seconds' => 2700,
            'status'           => ScreenRecording::STATUS_READY,
        ]);

        return Presentation::create([
            'title'            => 'Tuesday Presentation',
            'recording_id'     => $recording->id,
            'scheduled_at'     => now()->subMinutes(10),
            'started_at'       => now()->subMinutes(10),
            'duration_seconds' => 2700,
            'status'           => Presentation::STATUS_LIVE,
        ]);
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

    // ── The listing ───────────────────────────────────────────────────────────

    public function test_a_member_sees_only_their_own_guests_in_the_panel(): void
    {
        $response = $this->actingAs($this->alice)
            ->getJson("/member/presentations/{$this->presentation->slug}/attendees")
            ->assertOk();

        $emails = collect($response->json('attendees'))->pluck('email')->all();

        $this->assertSame(['aguest@example.com'], $emails);
        $this->assertSame(1, $response->json('total'));
    }

    /**
     * The console renders its guest list from the feed, so the HTML itself
     * should carry no guest data at all — not even the member's own. That is a
     * stronger position than filtering the page correctly: there is nothing in
     * the markup to leak, whatever a future template change does.
     */
    public function test_the_rendered_page_carries_no_guest_data_whatsoever(): void
    {
        $page = $this->actingAs($this->alice)
            ->get("/member/presentations/{$this->presentation->slug}")
            ->assertOk();

        foreach (['aguest@example.com', 'bguest@example.com', 'orphan@example.com',
                  'Alice Guest', 'Bob Guest', 'Nobody Sent Me'] as $secret) {
            $page->assertDontSee($secret);
        }
    }

    public function test_the_feed_that_fills_the_console_is_scoped_to_the_member(): void
    {
        $feed = $this->actingAs($this->alice)
            ->getJson("/member/presentations/{$this->presentation->slug}/attendees")
            ->assertOk();

        $emails = collect($feed->json('attendees'))->pluck('email');

        $this->assertTrue($emails->contains('aguest@example.com'));
        $this->assertFalse($emails->contains('bguest@example.com'));
        $this->assertFalse($emails->contains('orphan@example.com'));
    }

    public function test_an_unattributed_guest_belongs_to_no_member(): void
    {
        foreach ([$this->alice, $this->bob] as $member) {
            $emails = collect(
                $this->actingAs($member)
                    ->getJson("/member/presentations/{$this->presentation->slug}/attendees")
                    ->json('attendees')
            )->pluck('email');

            $this->assertFalse(
                $emails->contains('orphan@example.com'),
                'A guest with no inviting member must not surface for any member.'
            );
        }
    }

    // ── The scope itself ──────────────────────────────────────────────────────

    public function test_the_visibility_scope_refuses_to_return_another_members_guest_by_id(): void
    {
        $found = PresentationAttendee::query()
            ->visibleTo($this->alice)
            ->whereKey($this->bobGuest->id)
            ->first();

        $this->assertNull($found, 'visibleTo() must not return another member\'s guest, even by id.');
    }

    public function test_the_visibility_scope_refuses_a_lookup_by_email(): void
    {
        $found = PresentationAttendee::query()
            ->visibleTo($this->alice)
            ->where('email', $this->bobGuest->email)
            ->first();

        $this->assertNull($found, 'Knowing the email must not be enough to reach the guest.');
    }

    public function test_the_visibility_scope_returns_nothing_for_a_signed_out_visitor(): void
    {
        $this->assertSame(0, PresentationAttendee::query()->visibleTo(null)->count());
    }

    public function test_admins_see_every_guest_including_the_unattributed(): void
    {
        $emails = PresentationAttendee::query()
            ->visibleTo($this->admin)
            ->pluck('email')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            ['aguest@example.com', 'bguest@example.com', 'orphan@example.com'],
            $emails,
        );
    }

    public function test_is_visible_to_agrees_with_the_scope(): void
    {
        $this->assertTrue($this->aliceGuest->isVisibleTo($this->alice));
        $this->assertFalse($this->aliceGuest->isVisibleTo($this->bob));
        $this->assertFalse($this->orphanGuest->isVisibleTo($this->alice));
        $this->assertTrue($this->orphanGuest->isVisibleTo($this->admin));
        $this->assertFalse($this->aliceGuest->isVisibleTo(null));
    }

    // ── Guests are not members ────────────────────────────────────────────────

    public function test_a_guest_cannot_reach_the_member_area(): void
    {
        $this->get("/member/presentations/{$this->presentation->slug}")
            ->assertRedirect();  // to login — a guest has no account at all
    }

    public function test_a_member_cannot_reach_the_admin_console(): void
    {
        $this->actingAs($this->alice)
            ->get("/admin/presentations/{$this->presentation->slug}")
            ->assertForbidden();
    }
}
