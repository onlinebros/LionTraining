<?php

namespace Tests\Feature;

use App\Models\Presentation;
use App\Models\PresentationAttendee;
use App\Models\PresentationAttendeeClaim;
use App\Models\Role;
use App\Models\ScreenRecording;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guests registering, arriving, and being attributed to a member.
 */
class PresentationRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;
    private User $bob;
    private Presentation $presentation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleSeeder::class);

        // The member side is gated until release; these tests exercise the
        // released behaviour. PresentationAccessTest covers the gate itself.
        config(['presentations.open_to_members' => true]);


        $this->alice = $this->member('Alice');
        $this->bob   = $this->member('Bob');

        $recording = ScreenRecording::create([
            'title'            => 'Opportunity Talk',
            'disk'             => 'public',
            'path'             => 'recordings/talk.mp4',
            'duration_seconds' => 1800,
            'status'           => ScreenRecording::STATUS_READY,
        ]);

        $this->presentation = Presentation::create([
            'title'            => 'Tuesday Presentation',
            'recording_id'     => $recording->id,
            'scheduled_at'     => now()->addHour(),
            'duration_seconds' => 1800,
            'status'           => Presentation::STATUS_SCHEDULED,
        ]);
    }

    private function member(string $name): User
    {
        $user = User::create([
            'name'     => $name,
            'email'    => strtolower($name).'-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $user->forceFill([
            'role_id'   => Role::findByName(Role::PAID_MEMBER)->id,
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

    private function register(?User $host, string $email, string $name = 'Guest Person')
    {
        return $this->post("/watch/{$this->presentation->slug}/register", [
            'name'  => $name,
            'email' => $email,
            'code'  => $host?->referral_code,
        ]);
    }

    /**
     * Act as a registered guest.
     *
     * The test client does not carry response cookies into the next request, so
     * the attendee token that registration issued has to be handed back by
     * hand. withCookie() (not withUnencryptedCookie) because EncryptCookies is
     * active on the web group and discards anything it cannot decrypt.
     */
    private function asGuest(string $email): static
    {
        $attendee = PresentationAttendee::where('email', $email)->firstOrFail();

        return $this->withCookie('pres_'.$this->presentation->id, $attendee->token);
    }

    // ── Registering ───────────────────────────────────────────────────────────

    public function test_a_guest_registers_through_a_members_link_and_belongs_to_them(): void
    {
        $this->get("/watch/{$this->presentation->slug}/{$this->alice->referral_code}")
            ->assertOk()
            ->assertSee('Your name')
            ->assertSee($this->alice->name);

        $this->register($this->alice, 'guest@example.com')->assertRedirect();

        $attendee = PresentationAttendee::where('email', 'guest@example.com')->firstOrFail();

        $this->assertSame($this->alice->id, $attendee->host_user_id);
        $this->assertSame('Guest Person', $attendee->name);
        $this->assertNotEmpty($attendee->token);
    }

    /*
     * Every guest must arrive through somebody's invite code. A guest with no
     * inviting member is an orphan nobody can claim afterwards, which turns
     * into a commission argument that cannot be settled — so the bare link and
     * the mistyped code are both closed doors, not lenient ones.
     */

    public function test_the_bare_link_with_no_invite_code_is_refused(): void
    {
        $this->get("/watch/{$this->presentation->slug}")
            ->assertNotFound()
            ->assertSee('You need an invitation link');
    }

    public function test_an_unknown_invite_code_is_refused(): void
    {
        $this->get("/watch/{$this->presentation->slug}/NOTAREALCODE")
            ->assertNotFound()
            ->assertSee('not valid');
    }

    public function test_registering_without_a_code_creates_nobody(): void
    {
        $this->post("/watch/{$this->presentation->slug}/register", [
            'name' => 'Sneaky', 'email' => 'sneaky@example.com',
        ])->assertSessionHasErrors('code');

        $this->assertNull(PresentationAttendee::where('email', 'sneaky@example.com')->first());
    }

    public function test_registering_with_a_forged_code_creates_nobody(): void
    {
        // The form posts the code back as a field, and a field can be edited.
        $this->post("/watch/{$this->presentation->slug}/register", [
            'name' => 'Typo Victim', 'email' => 'typo@example.com', 'code' => 'NOTAREALCODE',
        ])->assertSessionHas('error');

        $this->assertNull(PresentationAttendee::where('email', 'typo@example.com')->first());
    }

    public function test_a_returning_guest_gets_in_on_their_cookie_without_the_code(): void
    {
        $this->register($this->alice, 'returning@example.com');

        // They were attributed when they registered; making them dig the
        // original link out again to come back would be absurd.
        $this->asGuest('returning@example.com')
            ->get("/watch/{$this->presentation->slug}")
            ->assertOk();
    }

    public function test_an_admins_own_code_works_because_head_office_sponsors_too(): void
    {
        $admin = User::create([
            'name' => 'Top Position', 'email' => 'top-'.uniqid().'@example.com', 'password' => 'password',
        ]);
        $admin->forceFill([
            'role_id'   => Role::findByName(Role::SUPER_ADMIN)->id,
            'is_active' => true,
        ])->save();

        $this->register($admin->refresh(), 'direct@example.com')->assertRedirect();

        $this->assertSame(
            $admin->id,
            PresentationAttendee::where('email', 'direct@example.com')->first()->host_user_id,
        );
    }

    public function test_email_is_normalised_so_case_cannot_duplicate_a_guest(): void
    {
        $this->register($this->alice, 'Mixed.Case@Example.com');
        $this->register($this->alice, 'mixed.case@example.com');

        $this->assertSame(1, PresentationAttendee::where('email', 'mixed.case@example.com')->count());
    }

    public function test_registration_is_refused_when_the_presentation_is_closed(): void
    {
        $this->presentation->update(['is_open' => false]);

        $this->register($this->alice, 'late@example.com')->assertSessionHas('error');

        $this->assertNull(PresentationAttendee::where('email', 'late@example.com')->first());
    }

    // ── Attribution ───────────────────────────────────────────────────────────

    public function test_the_first_member_to_register_a_guest_keeps_them(): void
    {
        $this->register($this->alice, 'contested@example.com');

        // Bob sends his link to the same person, who registers again.
        $this->register($this->bob, 'contested@example.com');

        $attendee = PresentationAttendee::where('email', 'contested@example.com')->firstOrFail();

        $this->assertSame($this->alice->id, $attendee->host_user_id, 'The first inviter keeps the guest.');
        $this->assertSame(1, PresentationAttendee::where('email', 'contested@example.com')->count());
    }

    public function test_the_losing_member_gets_a_claim_row_and_never_sees_the_guest(): void
    {
        $this->register($this->alice, 'contested@example.com');
        $this->register($this->bob, 'contested@example.com');

        $this->assertDatabaseHas('presentation_attendee_claims', [
            'claimed_by_user_id' => $this->bob->id,
        ]);

        // Bob's panel must still show nothing — the claim is for admins only.
        $emails = collect(
            $this->actingAs($this->bob)
                ->getJson("/member/presentations/{$this->presentation->slug}/attendees")
                ->json('attendees')
        )->pluck('email');

        $this->assertFalse($emails->contains('contested@example.com'));
    }

    public function test_a_repeat_claim_does_not_pile_up_rows(): void
    {
        $this->register($this->alice, 'contested@example.com');
        $this->register($this->bob, 'contested@example.com');
        $this->register($this->bob, 'contested@example.com');

        $this->assertSame(1, PresentationAttendeeClaim::where('claimed_by_user_id', $this->bob->id)->count());
    }

    /**
     * Adoption survives as a safety net rather than a route anyone can take:
     * the front door now refuses an uncoded guest, so the only way to hold an
     * unattributed row is history or a deleted host. If one exists, the first
     * member to actually bring that person should get them.
     */
    public function test_an_unattributed_guest_is_adopted_by_the_member_who_brings_them(): void
    {
        $orphan = $this->presentation->attendees()->create([
            'host_user_id'  => null,
            'name'          => 'Legacy Guest',
            'email'         => 'orphan@example.com',
            'token'         => bin2hex(random_bytes(16)),
            'registered_at' => now(),
        ]);

        $this->register($this->bob, 'orphan@example.com');

        $this->assertSame($this->bob->id, $orphan->refresh()->host_user_id);
    }

    // ── Arriving and watching ─────────────────────────────────────────────────

    public function test_joining_records_how_far_into_the_presentation_they_arrived(): void
    {
        $this->register($this->alice, 'latecomer@example.com');

        // The showing starts, and they arrive five minutes in.
        $this->presentation->forceFill([
            'status'     => Presentation::STATUS_LIVE,
            'started_at' => now()->subMinutes(5),
        ])->save();

        $this->asGuest('latecomer@example.com')
            ->get("/watch/{$this->presentation->slug}/{$this->alice->referral_code}")
            ->assertOk();

        $attendee = PresentationAttendee::where('email', 'latecomer@example.com')->firstOrFail();

        $this->assertNotNull($attendee->first_joined_at);
        $this->assertEqualsWithDelta(300, $attendee->joined_at_offset, 5);
    }

    public function test_a_heartbeat_cannot_claim_more_progress_than_the_room_has_made(): void
    {
        $this->register($this->alice, 'liar@example.com');

        $this->presentation->forceFill([
            'status'     => Presentation::STATUS_LIVE,
            'started_at' => now()->subMinutes(2),
        ])->save();

        $this->asGuest('liar@example.com')
            ->post("/watch/{$this->presentation->slug}/heartbeat", ['position' => 99999])
            ->assertOk();

        $attendee = PresentationAttendee::where('email', 'liar@example.com')->firstOrFail();

        // Clamped to where the presentation actually is (~120s), not what was sent.
        $this->assertLessThanOrEqual(130, $attendee->position_seconds);
    }

    public function test_a_heartbeat_without_registering_is_refused(): void
    {
        $this->post("/watch/{$this->presentation->slug}/heartbeat", ['position' => 10])
            ->assertStatus(403);
    }

    // ── The join CTA ──────────────────────────────────────────────────────────

    public function test_clicking_the_cta_stamps_the_attendee_once(): void
    {
        $this->register($this->alice, 'keen@example.com', 'Keen Prospect');

        $this->asGuest('keen@example.com')->post("/watch/{$this->presentation->slug}/cta")->assertOk();

        $attendee = PresentationAttendee::where('email', 'keen@example.com')->firstOrFail();
        $first    = $attendee->cta_clicked_at;
        $this->assertNotNull($first);

        // A second click is not a second intent; the first moment stands.
        $this->travel(5)->minutes();
        $this->asGuest('keen@example.com')->post("/watch/{$this->presentation->slug}/cta")->assertOk();

        $this->assertTrue($first->equalTo($attendee->refresh()->cta_clicked_at));
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public function test_the_scheduler_starts_a_due_presentation_at_its_scheduled_time(): void
    {
        $this->presentation->update(['scheduled_at' => now()->subSeconds(45)]);

        $this->artisan('presentations:run')->assertExitCode(0);

        $this->presentation->refresh();

        $this->assertTrue($this->presentation->isLive());

        // started_at must be the SCHEDULED time, not "now" — otherwise a late
        // timer shifts every viewer's position and the showing ends late.
        $this->assertSame(
            $this->presentation->scheduled_at->timestamp,
            $this->presentation->started_at->timestamp,
        );
    }

    public function test_the_scheduler_ends_a_presentation_whose_video_has_run_out(): void
    {
        $this->presentation->forceFill([
            'status'     => Presentation::STATUS_LIVE,
            'started_at' => now()->subSeconds(1800 + 30),
        ])->save();

        $this->artisan('presentations:run')->assertExitCode(0);

        $this->assertTrue($this->presentation->refresh()->hasEnded());
    }

    public function test_a_future_presentation_is_left_alone(): void
    {
        $this->artisan('presentations:run')->assertExitCode(0);

        $this->assertTrue($this->presentation->refresh()->isScheduled());
    }
}
