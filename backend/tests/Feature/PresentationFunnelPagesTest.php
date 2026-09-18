<?php

namespace Tests\Feature;

use App\Models\CtaItem;
use App\Models\Presentation;
use App\Models\PresentationCue;
use App\Models\PresentationFunnel;
use App\Models\Role;
use App\Models\ScreenRecording;
use App\Models\User;
use App\Support\PresentationCta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every page the funnel feature adds, rendered.
 *
 * A Blade error is invisible until somebody opens the page, and these pages are
 * where an admin builds the thing and a member watches it work — so each one is
 * walked here rather than trusted.
 */
class PresentationFunnelPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $alice;
    private PresentationFunnel $funnel;
    private Presentation $intro;
    private Presentation $numbers;
    private CtaItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleSeeder::class);
        config(['presentations.open_to_members' => true]);

        $this->admin = $this->user('Admin', Role::SUPER_ADMIN);
        $this->alice = $this->user('Alice');

        $this->funnel = PresentationFunnel::create([
            'title'            => 'Homeowner walkthrough',
            'slug'             => PresentationFunnel::uniqueSlug('Homeowner walkthrough'),
            'is_active'        => true,
            'member_shareable' => true,
        ]);

        $this->intro   = $this->step('Where to start', 0);
        $this->numbers = $this->step('The numbers', 1);
        $this->funnel->forceFill(['entry_presentation_id' => $this->intro->id])->save();

        $this->item = CtaItem::create([
            'name' => 'Book a call',
            'kind' => PresentationCta::SCHEDULE,
        ]);

        PresentationCue::create([
            'presentation_id'      => $this->intro->id,
            'kind'                 => PresentationCue::KIND_BRANCH,
            'next_presentation_id' => $this->numbers->id,
            'label'                => 'Show me the numbers',
            'starts_at_seconds'    => 90,
        ]);

        PresentationCue::create([
            'presentation_id'   => $this->numbers->id,
            'kind'              => PresentationCue::KIND_CTA,
            'cta_item_id'       => $this->item->id,
            'starts_at_seconds' => 200,
        ]);
    }

    private function user(string $name, string $role = Role::PAID_MEMBER): User
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

    private function step(string $title, int $order): Presentation
    {
        $recording = ScreenRecording::create([
            'title'            => $title,
            'disk'             => 'public',
            'path'             => 'recordings/'.uniqid().'.mp4',
            'duration_seconds' => 600,
            'status'           => ScreenRecording::STATUS_READY,
        ]);

        return Presentation::create([
            'title'            => $title,
            'slug'             => Presentation::uniqueSlug($title),
            'format'           => Presentation::FORMAT_ON_DEMAND,
            'recording_id'     => $recording->id,
            'scheduled_at'     => now(),
            'started_at'       => now(),
            'duration_seconds' => 600,
            'status'           => Presentation::STATUS_LIVE,
            'funnel_id'        => $this->funnel->id,
            'funnel_order'     => $order,
        ]);
    }

    // ── Admin ─────────────────────────────────────────────────────────────────

    public function test_the_admin_pages_render(): void
    {
        $this->actingAs($this->admin)->get('/admin/funnels')->assertOk()->assertSee('Homeowner walkthrough');
        $this->actingAs($this->admin)->get('/admin/funnels/create')->assertOk();
        $this->actingAs($this->admin)->get('/admin/funnels/'.$this->funnel->slug)
            ->assertOk()
            ->assertSee('Show me the numbers')
            ->assertSee('Starts here');
        $this->actingAs($this->admin)->get('/admin/funnels/'.$this->funnel->slug.'/edit')->assertOk();
        $this->actingAs($this->admin)->get('/admin/funnels/'.$this->funnel->slug.'/prospects')->assertOk();

        $this->actingAs($this->admin)->get('/admin/cta-items')->assertOk()->assertSee('Book a call');
        $this->actingAs($this->admin)->get('/admin/cta-items/create')->assertOk();
        $this->actingAs($this->admin)->get('/admin/cta-items/'.$this->item->id.'/edit')->assertOk();
    }

    public function test_an_admin_can_build_a_flow_from_the_page(): void
    {
        $extra = ScreenRecording::create([
            'title'            => 'Financing',
            'disk'             => 'public',
            'path'             => 'recordings/financing.mp4',
            'duration_seconds' => 480,
            'status'           => ScreenRecording::STATUS_READY,
        ]);

        $this->actingAs($this->admin)
            ->post('/admin/funnels/'.$this->funnel->slug.'/steps', ['recording_id' => $extra->id])
            ->assertRedirect();

        $step = $this->funnel->fresh()->steps()->where('title', 'Financing')->first();

        $this->assertNotNull($step);
        // A step is an ordinary always-open presentation. That is the whole
        // design — everything downstream treats it as one.
        $this->assertTrue($step->isOnDemand());
        $this->assertSame(Presentation::STATUS_LIVE, $step->status);

        // Times are typed the way they are read off the video.
        $this->actingAs($this->admin)
            ->post('/admin/funnels/cues/'.$step->slug, [
                'kind'        => PresentationCue::KIND_CTA,
                'cta_item_id' => $this->item->id,
                'starts_at'   => '4:30',
            ])
            ->assertRedirect();

        $this->assertSame(270, $step->cues()->first()->starts_at_seconds);
    }

    public function test_a_choice_cannot_be_placed_past_the_end_of_its_video(): void
    {
        // A choice offered after the video finishes is a choice nobody sees.
        $this->actingAs($this->admin)
            ->post('/admin/funnels/cues/'.$this->intro->slug, [
                'kind'        => PresentationCue::KIND_CTA,
                'cta_item_id' => $this->item->id,
                'starts_at'   => '20:00',
            ])
            ->assertSessionHasErrors('starts_at');
    }

    public function test_a_video_cannot_lead_to_itself(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/funnels/cues/'.$this->intro->slug, [
                'kind'                 => PresentationCue::KIND_BRANCH,
                'next_presentation_id' => $this->intro->id,
                'starts_at'            => '1:00',
            ])
            ->assertSessionHasErrors('next_presentation_id');
    }

    public function test_a_call_to_action_in_use_is_not_deleted_by_accident(): void
    {
        $this->actingAs($this->admin)
            ->delete('/admin/cta-items/'.$this->item->id)
            ->assertRedirect();

        // Silently emptying a video of its buttons is not something to do by
        // accident.
        $this->assertModelExists($this->item);
    }

    // ── Member ────────────────────────────────────────────────────────────────

    public function test_a_member_sees_a_released_flow_and_their_own_link(): void
    {
        $this->actingAs($this->alice)->get('/member/funnels')
            ->assertOk()
            ->assertSee('Homeowner walkthrough')
            ->assertSee('/flow/'.$this->funnel->slug.'/'.$this->alice->referral_code);

        $this->actingAs($this->alice)->get('/member/funnels/'.$this->funnel->slug)->assertOk();
    }

    public function test_a_member_cannot_reach_a_flow_head_office_has_not_released(): void
    {
        $this->funnel->update(['member_shareable' => false]);

        $this->actingAs($this->alice)->get('/member/funnels')
            ->assertOk()
            ->assertDontSee('Homeowner walkthrough');

        $this->actingAs($this->alice)->get('/member/funnels/'.$this->funnel->slug)->assertForbidden();

        // An admin is still building it, so they keep their own access.
        $this->actingAs($this->admin)->get('/member/funnels/'.$this->funnel->slug)->assertOk();
    }

    // ── The guest room ────────────────────────────────────────────────────────

    public function test_the_room_offers_the_choices_with_the_moment_each_appears(): void
    {
        $this->post('/flow/'.$this->funnel->slug.'/register', [
            'name'  => 'Casey',
            'email' => 'casey@example.com',
            'code'  => $this->alice->referral_code,
        ])->assertRedirect();

        $participant = \App\Models\FunnelParticipant::firstWhere('email', 'casey@example.com');

        $this->withCookie('pres_'.$this->intro->id, $participant->threadAttendee->token)
            ->get('/watch/'.$this->intro->slug.'/'.$this->alice->referral_code)
            ->assertOk()
            ->assertSee('Show me the numbers')
            ->assertSee('data-at="90"', false);
    }
}
