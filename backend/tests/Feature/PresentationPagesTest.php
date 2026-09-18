<?php

namespace Tests\Feature;

use App\Models\Presentation;
use App\Models\Role;
use App\Models\ScreenRecording;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Renders every page in the feature.
 *
 * A view referencing a route or column that no longer exists fails here rather
 * than in front of a room full of guests.
 */
class PresentationPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $member;
    private ScreenRecording $recording;
    private Presentation $presentation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleSeeder::class);

        // The member side is gated until release; these tests exercise the
        // released behaviour. PresentationAccessTest covers the gate itself.
        config(['presentations.open_to_members' => true]);


        $this->admin  = $this->user(Role::SUPER_ADMIN);
        $this->member = $this->user(Role::PAID_MEMBER);

        $this->recording = ScreenRecording::create([
            'title'            => 'Opportunity Talk',
            'disk'             => 'public',
            'path'             => 'recordings/talk.mp4',
            'mime'             => 'video/mp4',
            'duration_seconds' => 1800,
            'status'           => ScreenRecording::STATUS_READY,
        ]);

        $this->presentation = Presentation::create([
            'title'            => 'Tuesday Presentation',
            'recording_id'     => $this->recording->id,
            'scheduled_at'     => now()->addHour(),
            'duration_seconds' => 1800,
            'status'           => Presentation::STATUS_SCHEDULED,
            'created_by'       => $this->admin->id,
        ]);
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name'     => 'Page '.$role,
            'email'    => $role.'-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $user->forceFill([
            'role_id'   => Role::findByName($role)->id,
            'is_active' => true,
        ])->save();

        // /member sits behind RequireActiveSubscription; exempt accounts pass it.
        $user->forceFill(['billing_exempt' => true])->save();

        return $user->refresh();
    }

    public function test_admin_pages_render(): void
    {
        $this->actingAs($this->admin)->get('/admin/presentations')->assertOk();
        $this->actingAs($this->admin)->get('/admin/presentations/create')->assertOk()
            ->assertSee('Opportunity Talk');
        $this->actingAs($this->admin)->get("/admin/presentations/{$this->presentation->slug}")->assertOk()
            ->assertSee('Tuesday Presentation');
        $this->actingAs($this->admin)->get("/admin/presentations/{$this->presentation->slug}/edit")->assertOk();
    }

    public function test_member_pages_render_and_show_their_own_link(): void
    {
        $this->actingAs($this->member)->get('/member/presentations')->assertOk()
            ->assertSee('Tuesday Presentation');

        $this->actingAs($this->member)
            ->get("/member/presentations/{$this->presentation->slug}")
            ->assertOk()
            ->assertSee($this->member->referral_code, false);
    }

    public function test_the_guest_registration_page_renders(): void
    {
        $this->get("/watch/{$this->presentation->slug}/{$this->member->referral_code}")
            ->assertOk()
            ->assertSee('Tuesday Presentation')
            ->assertSee('Save my spot');
    }

    public function test_the_guest_room_renders_once_registered(): void
    {
        $this->post("/watch/{$this->presentation->slug}/register", [
            'name'  => 'Guest Person',
            'email' => 'guest@example.com',
            'code'  => $this->member->referral_code,
        ]);

        $attendee = $this->presentation->attendees()->firstOrFail();

        $this->withCookie('pres_'.$this->presentation->id, $attendee->token)
            ->get("/watch/{$this->presentation->slug}")
            ->assertOk()
            ->assertSee('See how to get started');
    }

    public function test_scheduling_a_presentation_copies_the_recordings_length(): void
    {
        $this->actingAs($this->admin)->post('/admin/presentations', [
            'title'             => 'Thursday Showing',
            'recording_id'      => $this->recording->id,
            'scheduled_at'      => now()->addDays(2)->format('Y-m-d\TH:i'),
            'replay_visibility' => Presentation::REPLAY_NONE,
        ])->assertRedirect();

        $created = Presentation::where('title', 'Thursday Showing')->firstOrFail();

        $this->assertSame(1800, $created->duration_seconds);
        $this->assertSame('thursday-showing', $created->slug);
    }

    public function test_the_start_time_of_a_running_presentation_cannot_be_moved(): void
    {
        $this->presentation->forceFill([
            'status'     => Presentation::STATUS_LIVE,
            'started_at' => now()->subMinutes(5),
        ])->save();

        $this->actingAs($this->admin)
            ->put("/admin/presentations/{$this->presentation->slug}", [
                'title'             => 'Tuesday Presentation',
                'recording_id'      => $this->recording->id,
                'scheduled_at'      => now()->addDays(3)->format('Y-m-d\TH:i'),
                'replay_visibility' => Presentation::REPLAY_NONE,
            ])
            ->assertSessionHasErrors('scheduled_at');
    }

    public function test_a_recording_in_use_by_a_presentation_cannot_be_deleted(): void
    {
        // restrictOnDelete: a scheduled showing pointing at a deleted file would
        // fail in front of an audience, so the database refuses it outright.
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->recording->forceDelete();
    }
}
