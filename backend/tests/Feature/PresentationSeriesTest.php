<?php

namespace Tests\Feature;

use App\Models\Presentation;
use App\Models\PresentationSeries;
use App\Models\Role;
use App\Models\ScreenRecording;
use App\Models\User;
use App\Services\Presentations\SeriesGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Repeating schedules: one recording, shown on set days at set times.
 *
 * The series is only a rule — what matters is that it produces real showings,
 * each with its own link and guest list, that it never backfills into the past,
 * and that it does not resurrect a showing somebody deliberately cancelled.
 */
class PresentationSeriesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private ScreenRecording $recording;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleSeeder::class);

        $this->admin = $this->adminUser();

        $this->recording = ScreenRecording::create([
            'title'            => 'Opportunity Talk',
            'disk'             => 'public',
            'path'             => 'recordings/talk.mp4',
            'mime'             => 'video/mp4',
            'duration_seconds' => 1800,
            'status'           => ScreenRecording::STATUS_READY,
        ]);

        // A fixed Monday, so "next Tuesday" is never ambiguous.
        Carbon::setTestNow(Carbon::parse('2026-09-07 09:00', 'America/New_York')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function adminUser(): User
    {
        $user = User::create([
            'name'     => 'Scheduler',
            'email'    => 'series-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $user->forceFill([
            'role_id'   => Role::findByName(Role::SUPER_ADMIN)->id,
            'is_active' => true,
        ])->save();

        return $user->refresh();
    }

    private function series(array $overrides = []): PresentationSeries
    {
        return PresentationSeries::create(array_merge([
            'recording_id' => $this->recording->id,
            'title'        => 'Weekly Opportunity',
            'days'         => [2, 4],          // Tuesday and Thursday
            'times'        => ['19:00'],
            'weeks_ahead'  => 2,
            'created_by'   => $this->admin->id,
        ], $overrides));
    }

    // ── Generating ────────────────────────────────────────────────────────────

    public function test_it_creates_a_showing_for_every_day_and_time_in_the_window(): void
    {
        $series = $this->series(['days' => [2, 4], 'times' => ['19:00', '12:00'], 'weeks_ahead' => 2]);

        $made = app(SeriesGenerator::class)->generate($series);

        // Two weekdays x two times x two weeks.
        $this->assertSame(8, $made);
        $this->assertSame(8, $series->presentations()->count());

        foreach ($series->presentations as $showing) {
            $local = $showing->scheduledAtLocal();

            $this->assertContains((int) $local->dayOfWeek, [2, 4]);
            $this->assertContains($local->format('H:i'), ['19:00', '12:00']);
            $this->assertSame(1800, $showing->duration_seconds);
            $this->assertSame($this->recording->id, $showing->recording_id);
        }
    }

    public function test_each_showing_gets_its_own_link(): void
    {
        $series = $this->series();
        app(SeriesGenerator::class)->generate($series);

        $slugs = $series->presentations->pluck('slug');

        $this->assertSame($slugs->count(), $slugs->unique()->count(), 'Slugs must not collide.');
    }

    public function test_running_it_twice_does_not_duplicate_anything(): void
    {
        $series = $this->series();

        $first  = app(SeriesGenerator::class)->generate($series);
        $second = app(SeriesGenerator::class)->generate($series);

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second, 'A second run should find everything already there.');
        $this->assertSame($first, $series->presentations()->count());
    }

    public function test_it_never_backfills_into_the_past(): void
    {
        // Today is a Monday; ask for Mondays. The one that has already gone by
        // this morning must not be created — the runner would start it at once
        // and it would end before anybody heard about it.
        $series = $this->series(['days' => [1], 'times' => ['08:00'], 'weeks_ahead' => 1]);

        app(SeriesGenerator::class)->generate($series);

        foreach ($series->presentations as $showing) {
            $this->assertTrue($showing->scheduled_at->isFuture());
        }
    }

    public function test_a_cancelled_showing_is_not_resurrected(): void
    {
        $series = $this->series();
        app(SeriesGenerator::class)->generate($series);

        $victim = $series->presentations()->orderBy('scheduled_at')->first();
        $when   = $victim->scheduled_at;

        $this->actingAs($this->admin)
            ->delete("/admin/presentations/{$victim->slug}")
            ->assertRedirect();

        app(SeriesGenerator::class)->generate($series);

        $this->assertSame(
            0,
            Presentation::where('series_id', $series->id)->where('scheduled_at', $when)->count(),
            'Cancelling a showing has to stick, or the generator undoes the admin.',
        );
    }

    public function test_a_stopped_series_generates_nothing(): void
    {
        $series = $this->series(['is_active' => false]);

        $this->assertSame(0, app(SeriesGenerator::class)->generate($series));
    }

    public function test_it_respects_a_last_date(): void
    {
        $series = $this->series([
            'days'        => [2, 4],
            'weeks_ahead' => 8,
            'ends_on'     => Carbon::parse('2026-09-17'),   // the second Thursday
        ]);

        app(SeriesGenerator::class)->generate($series);

        foreach ($series->presentations as $showing) {
            $this->assertTrue(
                $showing->scheduledAtLocal()->lte(Carbon::parse('2026-09-17 23:59', 'America/New_York')),
            );
        }
    }

    public function test_times_are_eastern_not_utc(): void
    {
        $series = $this->series(['days' => [2], 'times' => ['19:00'], 'weeks_ahead' => 1]);

        app(SeriesGenerator::class)->generate($series);

        $showing = $series->presentations()->firstOrFail();

        $this->assertSame('7:00pm', $showing->scheduledAtLocal()->format('g:ia'));
        // September is daylight saving, so Eastern is UTC-4.
        $this->assertSame('23:00', $showing->scheduled_at->utc()->format('H:i'));
    }

    // ── The command ───────────────────────────────────────────────────────────

    public function test_the_command_fills_every_active_series(): void
    {
        $this->series(['title' => 'One', 'days' => [2], 'weeks_ahead' => 1]);
        $this->series(['title' => 'Two', 'days' => [4], 'weeks_ahead' => 1]);
        $this->series(['title' => 'Off', 'days' => [5], 'weeks_ahead' => 1, 'is_active' => false]);

        $this->artisan('presentations:generate')->assertExitCode(0);

        $this->assertSame(1, Presentation::where('title', 'One')->count());
        $this->assertSame(1, Presentation::where('title', 'Two')->count());
        $this->assertSame(0, Presentation::where('title', 'Off')->count());
    }

    // ── The screens ───────────────────────────────────────────────────────────

    public function test_an_admin_can_create_a_schedule_through_the_form(): void
    {
        $this->actingAs($this->admin)->get('/admin/presentations/series/create')->assertOk();

        $this->actingAs($this->admin)->post('/admin/presentations/series', [
            'title'             => 'Tuesdays and Thursdays',
            'recording_id'      => $this->recording->id,
            'days'              => [2, 4],
            'times'             => ['19:00'],
            'weeks_ahead'       => 2,
            'replay_visibility' => Presentation::REPLAY_NONE,
        ])->assertRedirect();

        $series = PresentationSeries::where('title', 'Tuesdays and Thursdays')->firstOrFail();

        $this->assertSame([2, 4], $series->days);
        // Saving fills the calendar straight away rather than waiting for the
        // nightly command — an admin should see it worked.
        $this->assertGreaterThan(0, $series->presentations()->count());
    }

    public function test_duplicate_days_and_times_are_collapsed(): void
    {
        $this->actingAs($this->admin)->post('/admin/presentations/series', [
            'title'             => 'Sloppy input',
            'recording_id'      => $this->recording->id,
            'days'              => [2, 2, 4],
            'times'             => ['19:00', '19:00', '09:00'],
            'weeks_ahead'       => 1,
            'replay_visibility' => Presentation::REPLAY_NONE,
        ])->assertRedirect();

        $series = PresentationSeries::where('title', 'Sloppy input')->firstOrFail();

        $this->assertSame([2, 4], $series->days);
        $this->assertSame(['09:00', '19:00'], $series->times);
    }

    public function test_a_schedule_needs_at_least_one_day_and_one_time(): void
    {
        $this->actingAs($this->admin)->post('/admin/presentations/series', [
            'title'             => 'Nothing',
            'recording_id'      => $this->recording->id,
            'weeks_ahead'       => 2,
            'replay_visibility' => Presentation::REPLAY_NONE,
        ])->assertSessionHasErrors(['days', 'times']);
    }

    public function test_stopping_a_schedule_leaves_its_showings_alone(): void
    {
        $series = $this->series();
        app(SeriesGenerator::class)->generate($series);
        $count = $series->presentations()->count();

        $this->actingAs($this->admin)
            ->delete("/admin/presentations/series/{$series->id}")
            ->assertRedirect();

        // People may already hold links to these, and their guest lists are real.
        $this->assertSame($count, Presentation::where('series_id', $series->id)->count());
        $this->assertFalse($series->refresh()->is_active);
    }

    public function test_members_cannot_touch_schedules(): void
    {
        $member = User::create([
            'name' => 'M', 'email' => 'm-'.uniqid().'@example.com', 'password' => 'password',
        ]);
        $member->forceFill([
            'role_id'   => Role::findByName(Role::PAID_MEMBER)->id,
            'is_active' => true,
        ])->save();

        $this->actingAs($member->refresh())->get('/admin/presentations/series')->assertForbidden();
    }
}
