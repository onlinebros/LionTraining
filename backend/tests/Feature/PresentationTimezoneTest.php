<?php

namespace Tests\Feature;

use App\Models\Presentation;
use App\Models\Role;
use App\Models\ScreenRecording;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Everything is booked in Eastern; everything is stored in UTC.
 *
 * The case that matters is the daylight-saving one. "EST" is UTC-5 all year,
 * but the east coast runs on EDT (UTC-4) from March to November — so a fixed
 * offset would put every summer showing an hour away from what a clock in New
 * York actually reads. These tests pin both halves of the year.
 */
class PresentationTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private ScreenRecording $recording;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleSeeder::class);
        config(['presentations.open_to_members' => true]);

        $this->admin = $this->admin();

        $this->recording = ScreenRecording::create([
            'title'            => 'Opportunity Talk',
            'disk'             => 'public',
            'path'             => 'recordings/talk.mp4',
            'mime'             => 'video/mp4',
            'duration_seconds' => 1800,
            'status'           => ScreenRecording::STATUS_READY,
        ]);
    }

    private function admin(): User
    {
        $user = User::create([
            'name'     => 'Scheduler',
            'email'    => 'sched-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $user->forceFill([
            'role_id'   => Role::findByName(Role::SUPER_ADMIN)->id,
            'is_active' => true,
        ])->save();

        // /member sits behind RequireActiveSubscription; exempt accounts pass it.
        $user->forceFill(['billing_exempt' => true])->save();

        return $user->refresh();
    }

    private function schedule(string $easternWallClock, string $title = 'Showing'): Presentation
    {
        $this->actingAs($this->admin)->post('/admin/presentations', [
            'title'             => $title,
            'recording_id'      => $this->recording->id,
            'scheduled_at'      => $easternWallClock,
            'replay_visibility' => Presentation::REPLAY_NONE,
        ])->assertRedirect();

        return Presentation::where('title', $title)->firstOrFail();
    }

    // ── Storage ───────────────────────────────────────────────────────────────

    public function test_a_summer_booking_is_stored_as_utc_minus_four(): void
    {
        // 2 July 2026 is inside daylight saving: Eastern is UTC-4.
        $presentation = $this->schedule('2026-07-02T19:00', 'Summer');

        $this->assertSame(
            '2026-07-02 23:00:00',
            $presentation->scheduled_at->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_a_winter_booking_is_stored_as_utc_minus_five(): void
    {
        // 14 January 2027 is standard time: Eastern is UTC-5, so 7pm rolls over
        // midnight in UTC. Getting this wrong is a whole day out, not an hour.
        $presentation = $this->schedule('2027-01-14T19:00', 'Winter');

        $this->assertSame(
            '2027-01-15 00:00:00',
            $presentation->scheduled_at->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_the_stored_time_reads_back_as_the_clock_that_was_typed(): void
    {
        foreach ([['2026-07-02T19:00', 'S'], ['2027-01-14T19:00', 'W']] as [$input, $title]) {
            $presentation = $this->schedule($input, $title);

            $this->assertSame('7:00pm', $presentation->scheduledAtLocal()->format('g:ia'));
            $this->assertSame($input, $presentation->bookingInputValue());
        }
    }

    // ── Labels ────────────────────────────────────────────────────────────────

    public function test_the_abbreviation_follows_daylight_saving(): void
    {
        $this->assertSame('EDT', $this->schedule('2026-07-02T19:00', 'Summer')->timezoneAbbreviation());
        $this->assertSame('EST', $this->schedule('2027-01-14T19:00', 'Winter')->timezoneAbbreviation());
    }

    public function test_the_label_says_the_eastern_clock_not_the_utc_one(): void
    {
        $presentation = $this->schedule('2026-07-02T19:00', 'Summer');

        $this->assertStringContainsString('7:00pm', $presentation->scheduledLabel());
        $this->assertStringContainsString('EDT', $presentation->scheduledLabel());
        $this->assertStringContainsString('Thu 2 Jul', $presentation->scheduledLabel());

        // If this ever prints 11:00pm, the display is showing UTC.
        $this->assertStringNotContainsString('11:00pm', $presentation->scheduledLabel());
    }

    public function test_the_iso_handed_to_the_browser_is_utc(): void
    {
        $presentation = $this->schedule('2026-07-02T19:00', 'Summer');

        $this->assertStringStartsWith('2026-07-02T23:00:00', $presentation->scheduledAtIso());
    }

    // ── Round trip through the form ───────────────────────────────────────────

    public function test_editing_shows_the_eastern_time_again_and_saves_it_unchanged(): void
    {
        $presentation = $this->schedule('2026-07-02T19:00', 'Summer');

        $this->actingAs($this->admin)
            ->get("/admin/presentations/{$presentation->slug}/edit")
            ->assertOk()
            ->assertSee('2026-07-02T19:00', false);

        // Saving without touching the time must not drift it.
        $this->actingAs($this->admin)->put("/admin/presentations/{$presentation->slug}", [
            'title'             => 'Summer',
            'recording_id'      => $this->recording->id,
            'scheduled_at'      => $presentation->bookingInputValue(),
            'replay_visibility' => Presentation::REPLAY_NONE,
        ])->assertRedirect();

        $this->assertSame(
            '2026-07-02 23:00:00',
            $presentation->refresh()->scheduled_at->utc()->format('Y-m-d H:i:s'),
        );
    }

    // ── What people actually see ──────────────────────────────────────────────

    public function test_every_page_quotes_the_eastern_time(): void
    {
        $presentation = $this->schedule('2026-07-02T19:00', 'Summer');

        $pages = [
            "/admin/presentations",
            "/admin/presentations/{$presentation->slug}",
            "/member/presentations",
            "/member/presentations/{$presentation->slug}",
        ];

        foreach ($pages as $page) {
            $this->actingAs($this->admin)->get($page)
                ->assertOk()
                ->assertSee('7:00pm')
                ->assertSee('EDT');
        }

        // And the guest page, which has no account behind it at all. It needs
        // an invite code like every other way in.
        $this->get("/watch/{$presentation->slug}/{$this->admin->referral_code}")
            ->assertOk()
            ->assertSee('7:00pm')
            ->assertSee('EDT');
    }

    public function test_the_page_carries_the_utc_instant_for_the_browser_to_localise(): void
    {
        $presentation = $this->schedule('2026-07-02T19:00', 'Summer');

        $this->get("/watch/{$presentation->slug}/{$this->admin->referral_code}")
            ->assertOk()
            ->assertSee('data-utc="2026-07-02T23:00:00+00:00"', false)
            ->assertSee('your time', false);
    }

    // ── The clock the room runs on is unaffected ──────────────────────────────

    public function test_starting_a_showing_still_pins_it_to_the_scheduled_instant(): void
    {
        $presentation = $this->schedule('2026-07-02T19:00', 'Summer');

        // Pretend we are past it, so the runner picks it up.
        $this->travelTo($presentation->scheduled_at->copy()->addSeconds(30));

        $this->artisan('presentations:run')->assertExitCode(0);

        $presentation->refresh();

        $this->assertTrue($presentation->isLive());
        $this->assertSame(
            $presentation->scheduled_at->timestamp,
            $presentation->started_at->timestamp,
            'started_at must remain the scheduled instant, whatever timezone it was booked in.',
        );

        $this->travelBack();
    }
}
