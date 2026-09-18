<?php

namespace Tests\Feature;

use App\Models\CrmContact;
use App\Models\Presentation;
use App\Models\PresentationAttendee;
use App\Models\Role;
use App\Models\ScreenRecording;
use App\Models\User;
use App\Services\Presentations\ConversionTracker;
use App\Services\Presentations\ProspectReport;
use App\Services\Presentations\ProspectToCrm;
use App\Support\PresentationCta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Knowing which guests went on to sign up.
 *
 * The case that decides the design: people watch under a throwaway address and
 * sign up under their real one once they have decided. Matching on email alone
 * would miss most of the conversions that matter, so the link is a token
 * carried through the call-to-action click — it never looks at the address.
 */
class PresentationConversionTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;
    private Presentation $presentation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleSeeder::class);
        config(['presentations.open_to_members' => true]);

        $this->alice = $this->member('Alice');

        $recording = ScreenRecording::create([
            'title'            => 'Opportunity Talk',
            'disk'             => 'public',
            'path'             => 'recordings/talk.mp4',
            'duration_seconds' => 1800,
            'status'           => ScreenRecording::STATUS_READY,
        ]);

        $this->presentation = Presentation::create([
            'title'            => 'Tuesday',
            'recording_id'     => $recording->id,
            'scheduled_at'     => now()->subMinutes(5),
            'started_at'       => now()->subMinutes(5),
            'duration_seconds' => 1800,
            'status'           => Presentation::STATUS_LIVE,
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

    private function guest(string $email, ?Presentation $on = null): PresentationAttendee
    {
        return ($on ?? $this->presentation)->attendees()->create([
            'host_user_id'    => $this->alice->id,
            'name'            => 'Throwaway Person',
            'email'           => $email,
            'token'           => bin2hex(random_bytes(16)),
            'registered_at'   => now(),
            'first_joined_at' => now(),
            'joined_at_offset'=> 30,
            'watch_seconds'   => 600,
        ]);
    }

    /** Render the room as this guest and return the HTML. */
    private function room(PresentationAttendee $attendee): string
    {
        return $this->withCookie('pres_'.$this->presentation->id, $attendee->token)
            ->get("/watch/{$this->presentation->slug}/{$this->alice->referral_code}")
            ->assertOk()
            ->getContent();
    }

    // ── The button a guest actually sees ──────────────────────────────────────

    public function test_the_room_shows_the_call_to_action_the_showing_was_configured_with(): void
    {
        $this->presentation->forceFill([
            'cta_type'  => PresentationCta::SCHEDULE,
            'cta_label' => 'Book my call',
            'cta_url'   => 'https://example.com/book',
        ])->save();

        $html = $this->room($this->guest('someone@example.com'));

        // The room used to hard-code the recruiting button, which quietly made
        // the whole configuration ornamental — including "no button".
        $this->assertStringContainsString('Book my call', $html);
        $this->assertStringContainsString('https://example.com/book', $html);
        $this->assertStringNotContainsString('See how to get started', $html);
    }

    public function test_no_button_means_no_button(): void
    {
        $this->presentation->forceFill(['cta_type' => PresentationCta::NONE])->save();

        // The id, not the bare string: the player's script names the element
        // whether or not the page ever renders one.
        $this->assertStringNotContainsString('id="cta-button"', $this->room($this->guest('quiet@example.com')));
    }

    public function test_the_button_carries_the_conversion_token(): void
    {
        $attendee = $this->guest('tracked@example.com');
        $html     = $this->room($attendee);

        // Without this the token-based attribution never fires from the room,
        // and a sign-up under a second address is invisible.
        $this->assertStringContainsString(
            ConversionTracker::PARAM.'='.$this->conversions()->tokenFor($attendee->refresh()),
            $html,
        );
    }

    private function conversions(): ConversionTracker
    {
        return app(ConversionTracker::class);
    }

    // ── The call to action ────────────────────────────────────────────────────

    public function test_the_button_goes_where_the_showing_says(): void
    {
        $join = PresentationCta::for(
            tap($this->presentation)->forceFill(['cta_type' => PresentationCta::JOIN]),
            $this->alice,
        );

        $this->assertStringContainsString('/join/'.$this->alice->referral_code, $join->url);
        $this->assertSame('See how to get started', $join->label);

        // There is no in-house booking, so a call button needs its link — and
        // without one it is hidden rather than pointed somewhere wrong.
        $call = PresentationCta::for(
            tap($this->presentation)->forceFill(['cta_type' => PresentationCta::SCHEDULE, 'cta_url' => null]),
            $this->alice,
        );
        $this->assertFalse($call->isVisible());

        $call = PresentationCta::for(
            tap($this->presentation)->forceFill(['cta_url' => 'https://example.com/book']),
            $this->alice,
        );
        $this->assertSame('https://example.com/book', $call->url);
        $this->assertSame('Book my call', $call->label);
    }

    public function test_wording_and_destination_can_be_overridden(): void
    {
        $this->presentation->forceFill([
            'cta_type'     => PresentationCta::CUSTOM,
            'cta_label'    => 'Book a call',
            'cta_headline' => 'Want to talk it through?',
            'cta_url'      => 'https://example.com/book',
        ])->save();

        $cta = PresentationCta::for($this->presentation, $this->alice);

        $this->assertSame('Book a call', $cta->label);
        $this->assertSame('Want to talk it through?', $cta->headline);
        $this->assertSame('https://example.com/book', $cta->url);
    }

    public function test_a_showing_can_have_no_button_at_all(): void
    {
        $this->presentation->forceFill(['cta_type' => PresentationCta::NONE])->save();

        $this->assertFalse(PresentationCta::for($this->presentation, $this->alice)->isVisible());
    }

    // ── The token survives a change of email ──────────────────────────────────

    public function test_a_guest_who_signs_up_under_a_different_email_is_still_linked(): void
    {
        $guest = $this->guest('throwaway@example.com');
        $token = app(ConversionTracker::class)->tokenFor($guest);

        // They click through and register with the address they actually use.
        $this->get("/join/{$this->alice->referral_code}?".ConversionTracker::PARAM."={$token}")
            ->assertOk();

        $this->post("/join/{$this->alice->referral_code}", [
            'name'                  => 'Real Person',
            'email'                 => 'real@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect();

        $guest->refresh();
        $account = User::where('email', 'real@example.com')->firstOrFail();

        $this->assertSame($account->id, $guest->converted_user_id);
        $this->assertSame(ConversionTracker::BY_TOKEN, $guest->conversion_match);
        $this->assertTrue(
            $guest->signedUpUnderAnotherEmail(),
            'The two addresses differ — that is the whole reason the token exists.',
        );
    }

    public function test_the_token_credits_every_call_that_person_watched(): void
    {
        $second = Presentation::create([
            'title'            => 'Thursday',
            'recording_id'     => $this->presentation->recording_id,
            'scheduled_at'     => now()->subDay(),
            'duration_seconds' => 1800,
            'status'           => Presentation::STATUS_ENDED,
        ]);

        $first  = $this->guest('throwaway@example.com');
        $repeat = $this->guest('throwaway@example.com', $second);

        $token = app(ConversionTracker::class)->tokenFor($first);
        $this->get("/join/{$this->alice->referral_code}?".ConversionTracker::PARAM."={$token}");

        $this->post("/join/{$this->alice->referral_code}", [
            'name'                  => 'Real Person',
            'email'                 => 'real@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect();

        // Otherwise a member sees one conversion and a pile of unconverted rows
        // for the same human.
        $this->assertNotNull($repeat->refresh()->converted_user_id);
    }

    public function test_a_signup_with_no_token_links_nothing(): void
    {
        $guest = $this->guest('someone@example.com');

        $this->post("/join/{$this->alice->referral_code}", [
            'name'                  => 'Unrelated',
            'email'                 => 'unrelated@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect();

        $this->assertNull($guest->refresh()->converted_user_id);
    }

    // ── Email fallback ────────────────────────────────────────────────────────

    public function test_the_sweep_links_guests_who_used_their_real_address(): void
    {
        $guest   = $this->guest('honest@example.com');
        $account = $this->member('Honest');
        $account->forceFill(['email' => 'honest@example.com'])->save();

        $this->assertSame(1, app(ConversionTracker::class)->sweepByEmail());

        $guest->refresh();
        $this->assertSame($account->id, $guest->converted_user_id);
        $this->assertSame(ConversionTracker::BY_EMAIL, $guest->conversion_match);
    }

    public function test_the_sweep_never_overrides_a_token_match(): void
    {
        $guest = $this->guest('throwaway@example.com');
        $real  = $this->member('Real');

        app(ConversionTracker::class)->attribute($real, app(ConversionTracker::class)->tokenFor($guest));

        // Somebody else later registers with the throwaway address.
        $impostor = $this->member('Impostor');
        $impostor->forceFill(['email' => 'throwaway@example.com'])->save();

        app(ConversionTracker::class)->sweepByEmail();

        $this->assertSame(
            $real->id,
            $guest->refresh()->converted_user_id,
            'A token match is the stronger claim and must stand.',
        );
    }

    // ── The report ────────────────────────────────────────────────────────────

    public function test_the_report_is_one_row_per_person_not_per_registration(): void
    {
        $second = Presentation::create([
            'title'            => 'Thursday',
            'recording_id'     => $this->presentation->recording_id,
            'scheduled_at'     => now()->subDay(),
            'duration_seconds' => 1800,
            'status'           => Presentation::STATUS_ENDED,
        ]);

        $this->guest('repeat@example.com');
        $this->guest('repeat@example.com', $second);

        $rows = app(ProspectReport::class)->for($this->alice);

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['registrations']);
        $this->assertSame(1200, $rows[0]['watch_seconds']);
        $this->assertCount(2, $rows[0]['watched'], 'It should list what they have already seen.');
    }

    public function test_the_report_flags_a_changed_email(): void
    {
        $guest = $this->guest('throwaway@example.com');
        $real  = $this->member('Real');
        $real->forceFill(['email' => 'real@example.com'])->save();

        app(ConversionTracker::class)->attribute($real, app(ConversionTracker::class)->tokenFor($guest));

        $row = app(ProspectReport::class)->for($this->alice)->first();

        $this->assertTrue($row['converted']);
        $this->assertTrue($row['email_changed']);
        $this->assertSame('real@example.com', $row['converted_email']);
    }

    public function test_the_report_never_shows_another_members_prospects(): void
    {
        $bob = $this->member('Bob');

        $this->presentation->attendees()->create([
            'host_user_id'  => $bob->id,
            'name'          => 'Bobs Guest',
            'email'         => 'bobs@example.com',
            'token'         => bin2hex(random_bytes(16)),
            'registered_at' => now(),
        ]);

        $this->guest('alices@example.com');

        $emails = app(ProspectReport::class)->for($this->alice)->pluck('email');

        $this->assertTrue($emails->contains('alices@example.com'));
        $this->assertFalse($emails->contains('bobs@example.com'));
    }

    public function test_conversion_rate_is_measured_against_those_who_turned_up(): void
    {
        $watched = $this->guest('watched@example.com');
        $this->presentation->attendees()->create([
            'host_user_id'  => $this->alice->id,
            'name'          => 'No Show',
            'email'         => 'noshow@example.com',
            'token'         => bin2hex(random_bytes(16)),
            'registered_at' => now(),
        ]);

        $real = $this->member('Converted');
        app(ConversionTracker::class)->attribute($real, app(ConversionTracker::class)->tokenFor($watched));

        $totals = app(ProspectReport::class)->totals($this->alice);

        $this->assertSame(2, $totals['prospects']);
        $this->assertSame(1, $totals['attended']);
        $this->assertSame(1, $totals['converted']);
        // One of one who attended, not one of two invited.
        $this->assertSame(100.0, $totals['conversion']);
    }

    public function test_the_member_page_renders(): void
    {
        $this->guest('someone@example.com');

        $this->actingAs($this->alice)->get('/member/presentations/prospects')
            ->assertOk()
            ->assertSee('someone@example.com');
    }

    // ── CRM ───────────────────────────────────────────────────────────────────

    public function test_a_prospect_can_be_handed_to_the_crm_with_what_we_know(): void
    {
        $guest = $this->guest('prospect@example.com');

        $this->actingAs($this->alice)
            ->post("/member/presentations/prospects/{$guest->id}/crm")
            ->assertRedirect();

        $contact = CrmContact::where('email', 'prospect@example.com')->firstOrFail();

        $this->assertSame($this->alice->id, $contact->owner_id);
        // 'event' — the CRM's own vocabulary. The finer detail is in custom_data.
        $this->assertSame(ProspectToCrm::SOURCE, $contact->lead_source);
        $this->assertSame('event', $contact->lead_source);
        $this->assertSame($this->alice->id, $contact->referred_by_user_id);

        // The presentation's knowledge travels with it.
        $this->assertSame(1, $contact->custom_data['presentation']['registrations']);
        $this->assertSame(600, $contact->custom_data['presentation']['watch_seconds']);

        $this->assertSame($contact->id, $guest->refresh()->crm_contact_id);
    }

    public function test_a_member_cannot_file_another_members_prospect(): void
    {
        $bob   = $this->member('Bob');
        $guest = $this->presentation->attendees()->create([
            'host_user_id'  => $bob->id,
            'name'          => 'Bobs Guest',
            'email'         => 'bobs@example.com',
            'token'         => bin2hex(random_bytes(16)),
            'registered_at' => now(),
        ]);

        $this->actingAs($this->alice)
            ->post("/member/presentations/prospects/{$guest->id}/crm")
            ->assertNotFound();

        $this->assertNull(CrmContact::where('email', 'bobs@example.com')->first());
    }
}
