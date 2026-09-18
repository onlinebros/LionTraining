<?php

namespace Tests\Feature;

use App\Models\CtaItem;
use App\Models\FunnelParticipant;
use App\Models\Presentation;
use App\Models\PresentationCue;
use App\Models\PresentationFunnel;
use App\Models\Role;
use App\Models\ScreenRecording;
use App\Models\User;
use App\Support\PresentationCta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\NotificationService;
use Tests\Support\FakeNotifications;
use Tests\TestCase;

/**
 * A flow of videos the prospect steers themselves.
 *
 * The load-bearing claim is attribution: a person who follows three branches is
 * still, unambiguously, the prospect of the member who invited them. Everything
 * else — the branching, the tracking, the one continuous conversation — is
 * built on that and is worth nothing without it, so it is what these tests
 * press on hardest.
 */
class PresentationFunnelTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;
    private User $bob;
    private PresentationFunnel $funnel;
    private Presentation $intro;
    private Presentation $numbers;
    private Presentation $install;
    private CtaItem $bookCall;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleSeeder::class);
        config(['presentations.open_to_members' => true]);

        $this->alice = $this->member('Alice');
        $this->bob   = $this->member('Bob');

        $this->funnel = PresentationFunnel::create([
            'title'            => 'Homeowner walkthrough',
            'slug'             => PresentationFunnel::uniqueSlug('Homeowner walkthrough'),
            'is_active'        => true,
            'member_shareable' => true,
        ]);

        $this->intro   = $this->step('Where do you want to start?', 0);
        $this->numbers = $this->step('The numbers', 1);
        $this->install = $this->step('What installation looks like', 2);

        $this->funnel->forceFill(['entry_presentation_id' => $this->intro->id])->save();

        $this->bookCall = CtaItem::create([
            'name'  => 'Book a call',
            'kind'  => PresentationCta::SCHEDULE,
            'label' => 'Book my call',
            'url'   => 'https://example.com/book',
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

    private function step(string $title, int $order): Presentation
    {
        $recording = ScreenRecording::create([
            'title'            => $title,
            'disk'             => 'public',
            'path'             => 'recordings/'.uniqid().'.mp4',
            'mime'             => 'video/mp4',
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

    private function branch(Presentation $from, Presentation $to, string $label, int $at = 120): PresentationCue
    {
        return PresentationCue::create([
            'presentation_id'      => $from->id,
            'kind'                 => PresentationCue::KIND_BRANCH,
            'next_presentation_id' => $to->id,
            'label'                => $label,
            'starts_at_seconds'    => $at,
        ]);
    }

    private function ctaCue(Presentation $on, CtaItem $item, int $at = 300): PresentationCue
    {
        return PresentationCue::create([
            'presentation_id'   => $on->id,
            'kind'              => PresentationCue::KIND_CTA,
            'cta_item_id'       => $item->id,
            'starts_at_seconds' => $at,
        ]);
    }

    /** Walk somebody in through Alice's link and return them. */
    private function enter(string $name = 'Casey', string $email = 'casey@example.com'): FunnelParticipant
    {
        $this->post('/flow/'.$this->funnel->slug.'/register', [
            'name'  => $name,
            'email' => $email,
            'code'  => $this->alice->referral_code,
        ])->assertRedirect();

        return FunnelParticipant::where('email', $email)->firstOrFail();
    }

    // ── Getting in ────────────────────────────────────────────────────────────

    public function test_a_flow_cannot_be_entered_without_an_invite_code(): void
    {
        // The rule that makes the whole thing attributable. A prospect five
        // videos deep who belongs to nobody is worse than one who never
        // started, because by then somebody will be certain they own them.
        $this->get('/flow/'.$this->funnel->slug)->assertStatus(404);

        $this->get('/flow/'.$this->funnel->slug.'/'.$this->alice->referral_code)
            ->assertOk()
            ->assertSee('Homeowner walkthrough');
    }

    public function test_registering_starts_a_journey_on_the_first_video(): void
    {
        $participant = $this->enter();

        $this->assertSame($this->alice->id, $participant->host_user_id);
        $this->assertSame($this->intro->id, $participant->current_presentation_id);

        // The room they entered through is where the conversation lives, for
        // every video they go on to reach.
        $anchor = $participant->threadAttendee;
        $this->assertNotNull($anchor);
        $this->assertSame($this->intro->id, $anchor->presentation_id);
        $this->assertSame($this->alice->id, $anchor->host_user_id);
        $this->assertSame($participant->id, $anchor->funnel_participant_id);
    }

    public function test_coming_back_to_the_link_resumes_rather_than_starting_again(): void
    {
        $participant = $this->enter();

        $this->post('/flow/'.$this->funnel->slug.'/register', [
            'name'  => 'Casey',
            'email' => 'casey@example.com',
            'code'  => $this->alice->referral_code,
        ])->assertRedirect();

        $this->assertSame(1, FunnelParticipant::where('email', 'casey@example.com')->count());
        $this->assertSame($participant->id, FunnelParticipant::firstWhere('email', 'casey@example.com')->id);
    }

    // ── Choosing ──────────────────────────────────────────────────────────────

    public function test_choosing_a_branch_moves_them_on_and_keeps_them_attributed(): void
    {
        $participant = $this->enter();
        $cue         = $this->branch($this->intro, $this->numbers, 'Show me the numbers');

        $answer = $this->withCredentials()
            ->withCookie('pres_'.$this->intro->id, $participant->threadAttendee->token)
            ->postJson('/watch/'.$this->intro->slug.'/choose', ['cue' => $cue->id, 'position' => 140])
            ->assertOk()
            ->json();

        $this->assertSame(PresentationCue::KIND_BRANCH, $answer['kind']);
        $this->assertStringContainsString($this->numbers->slug, $answer['url']);

        $participant->refresh();
        $this->assertSame($this->numbers->id, $participant->current_presentation_id);

        // The point of the whole design: the next video's attendee row belongs
        // to the same member, without the guest having to re-arrive on their
        // link.
        $next = $participant->attendees()->where('presentation_id', $this->numbers->id)->first();
        $this->assertNotNull($next);
        $this->assertSame($this->alice->id, $next->host_user_id);
        $this->assertSame($participant->id, $next->funnel_participant_id);
    }

    public function test_every_choice_is_kept_with_where_they_were_when_they_made_it(): void
    {
        $participant = $this->enter();
        $cue         = $this->branch($this->intro, $this->numbers, 'Show me the numbers');

        $this->withCredentials()
            ->withCookie('pres_'.$this->intro->id, $participant->threadAttendee->token)
            ->postJson('/watch/'.$this->intro->slug.'/choose', ['cue' => $cue->id, 'position' => 140]);

        $event = $participant->choices()->first();

        $this->assertNotNull($event);
        $this->assertSame('Show me the numbers', $event->label);
        $this->assertSame($this->intro->id, $event->presentation_id);
        $this->assertSame($this->numbers->id, $event->next_presentation_id);
        $this->assertSame(140, $event->at_seconds);
    }

    public function test_the_wording_of_a_choice_survives_the_cue_being_reworded(): void
    {
        $participant = $this->enter();
        $cue         = $this->branch($this->intro, $this->numbers, 'Show me the numbers');

        $this->withCredentials()
            ->withCookie('pres_'.$this->intro->id, $participant->threadAttendee->token)
            ->postJson('/watch/'.$this->intro->slug.'/choose', ['cue' => $cue->id]);

        $cue->update(['label' => 'Something else entirely']);

        // What somebody actually chose must not change under them.
        $this->assertSame('Show me the numbers', $participant->choices()->first()->label);
    }

    public function test_a_call_to_action_ends_the_journey_and_records_what_they_asked_for(): void
    {
        $participant = $this->enter();
        $join        = CtaItem::create(['name' => 'Join', 'kind' => PresentationCta::JOIN]);
        $cue         = $this->ctaCue($this->intro, $join);

        $answer = $this->withCredentials()
            ->withCookie('pres_'.$this->intro->id, $participant->threadAttendee->token)
            ->postJson('/watch/'.$this->intro->slug.'/choose', ['cue' => $cue->id, 'position' => 320])
            ->assertOk()
            ->json();

        $this->assertSame(PresentationCue::KIND_CTA, $answer['kind']);
        // Credited to Alice, and carrying the token that links a sign-up under
        // any address back to this moment.
        $this->assertStringContainsString($this->alice->referral_code, $answer['url']);
        $this->assertStringContainsString(\App\Services\Presentations\ConversionTracker::PARAM.'=', $answer['url']);

        $participant->refresh();
        $this->assertSame(FunnelParticipant::OUTCOME_MEMBER, $participant->outcome);
        $this->assertNotNull($participant->finished_at);
        $this->assertNotNull($participant->threadAttendee->refresh()->cta_clicked_at);
    }

    public function test_a_cue_from_another_video_is_not_a_choice_offered_here(): void
    {
        $participant = $this->enter();
        $elsewhere   = $this->branch($this->numbers, $this->install, 'Not on this page');

        $this->withCredentials()
            ->withCookie('pres_'.$this->intro->id, $participant->threadAttendee->token)
            ->postJson('/watch/'.$this->intro->slug.'/choose', ['cue' => $elsewhere->id])
            ->assertStatus(404);

        $this->assertSame(0, $participant->choices()->count());
    }

    public function test_a_branch_whose_target_is_gone_is_never_offered(): void
    {
        $cue = $this->branch($this->intro, $this->numbers, 'Show me the numbers');

        $this->numbers->delete();

        // Better it never appears than that a prospect clicks it at the one
        // moment they were ready to act.
        $this->assertFalse($cue->fresh()->isPlayable());
        $this->assertTrue($this->intro->fresh()->playableCues()->isEmpty());
    }

    // ── One conversation, whatever they are watching ──────────────────────────

    public function test_the_chat_follows_them_across_a_branch(): void
    {
        $participant = $this->enter();
        $cue         = $this->branch($this->intro, $this->numbers, 'Show me the numbers');
        $anchor      = $participant->threadAttendee;

        $this->withCredentials()->withCookie('pres_'.$this->intro->id, $anchor->token)
            ->postJson('/watch/'.$this->intro->slug.'/messages', ['body' => 'Before I moved on'])
            ->assertCreated();

        $this->withCredentials()->withCookie('pres_'.$this->intro->id, $anchor->token)
            ->postJson('/watch/'.$this->intro->slug.'/choose', ['cue' => $cue->id]);

        $next = $participant->fresh()->attendees()
            ->where('presentation_id', $this->numbers->id)->firstOrFail();

        $this->withCredentials()->withCookie('pres_'.$this->numbers->id, $next->token)
            ->postJson('/watch/'.$this->numbers->slug.'/messages', ['body' => 'And after'])
            ->assertCreated();

        // A member halfway through answering must not have their reply land in
        // a room the prospect has left.
        $this->assertSame(2, $anchor->messages()->count());
        $this->assertSame(0, $next->messages()->count());

        $seen = $this->withCredentials()->withCookie('pres_'.$this->numbers->id, $next->token)
            ->getJson('/watch/'.$this->numbers->slug.'/messages')
            ->assertOk()
            ->json('messages');

        $this->assertSame(['Before I moved on', 'And after'], array_column($seen, 'body'));
    }

    // ── Isolation ─────────────────────────────────────────────────────────────

    public function test_another_member_sees_none_of_it(): void
    {
        $participant = $this->enter();
        $cue         = $this->branch($this->intro, $this->numbers, 'Show me the numbers');

        $this->withCredentials()
            ->withCookie('pres_'.$this->intro->id, $participant->threadAttendee->token)
            ->postJson('/watch/'.$this->intro->slug.'/choose', ['cue' => $cue->id]);

        $this->assertTrue($participant->isVisibleTo($this->alice));
        $this->assertFalse($participant->isVisibleTo($this->bob));

        $this->assertSame(0, FunnelParticipant::visibleTo($this->bob)->count());
        $this->assertSame(1, FunnelParticipant::visibleTo($this->alice)->count());

        // The choices are exactly as private as the person who made them.
        $this->assertSame(0, \App\Models\FunnelChoiceEvent::visibleTo($this->bob)->count());
        $this->assertSame(1, \App\Models\FunnelChoiceEvent::visibleTo($this->alice)->count());
    }

    // ── How the destination opens ─────────────────────────────────────────────

    public function test_a_call_to_action_opens_beside_the_video_by_default(): void
    {
        $participant = $this->enter();
        $this->ctaCue($this->intro, $this->bookCall);

        $html = $this->withCookie('pres_'.$this->intro->id, $participant->threadAttendee->token)
            ->get('/watch/'.$this->intro->slug.'/'.$this->alice->referral_code)
            ->assertOk()
            ->getContent();

        // Something they fill in and come back from: they keep their place in
        // the video, and the member is still there in the chat.
        $this->assertStringContainsString('data-external="1"', $html);
    }

    public function test_a_call_to_action_can_take_them_straight_to_the_page(): void
    {
        $this->bookCall->update(['opens_in' => PresentationCta::OPENS_SAME]);

        $participant = $this->enter();
        $this->ctaCue($this->intro, $this->bookCall);

        $html = $this->withCookie('pres_'.$this->intro->id, $participant->threadAttendee->token)
            ->get('/watch/'.$this->intro->slug.'/'.$this->alice->referral_code)
            ->assertOk()
            ->getContent();

        // A flow done in steps: the destination is the next thing, and there is
        // nothing to come back to.
        $this->assertStringContainsString('data-external=""', $html);
        // Still the call-to-action styling — it is the button that ends the
        // journey either way.
        $this->assertStringContainsString('data-kind="cta"', $html);
    }

    public function test_the_small_print_does_not_promise_a_window_that_never_opens(): void
    {
        $stayPut = CtaItem::create([
            'name'     => 'Next step',
            'kind'     => PresentationCta::CUSTOM,
            'url'      => 'https://example.com/next',
            'opens_in' => PresentationCta::OPENS_SAME,
        ]);

        $this->assertNull($stayPut->resolve($this->alice)->note);
        $this->assertSame(
            'Opens in a new window, so you keep your place here.',
            $this->bookCall->resolve($this->alice)->note,
        );
    }

    public function test_a_branch_always_stays_in_the_tab(): void
    {
        $participant = $this->enter();
        $this->branch($this->intro, $this->numbers, 'Show me the numbers');

        $html = $this->withCookie('pres_'.$this->intro->id, $participant->threadAttendee->token)
            ->get('/watch/'.$this->intro->slug.'/'.$this->alice->referral_code)
            ->getContent();

        // It is the same journey continuing, so it replaces the page rather
        // than stacking another tab on somebody. Read off the button itself
        // rather than the page, so re-indenting the template cannot pass this.
        preg_match('/<button[^>]*data-kind="branch"[^>]*>/', $html, $m);

        $this->assertNotEmpty($m, 'The branch button was not rendered.');
        $this->assertStringContainsString('data-external=""', $m[0]);
    }

    // ── In the console ────────────────────────────────────────────────────────

    public function test_the_console_shows_one_person_not_one_row_per_video(): void
    {
        $participant = $this->enter();
        $cue         = $this->branch($this->intro, $this->numbers, 'Show me the numbers');

        $this->withCredentials()
            ->withCookie('pres_'.$this->intro->id, $participant->threadAttendee->token)
            ->postJson('/watch/'.$this->intro->slug.'/choose', ['cue' => $cue->id, 'position' => 140]);

        $feed = $this->actingAs($this->alice)
            ->getJson('/member/presentations/live/feed')
            ->assertOk()
            ->json();

        // Two attendee rows exist; one human is shown.
        $this->assertSame(2, $participant->fresh()->attendees()->count());
        $this->assertCount(1, $feed['attendees']);

        $row = $feed['attendees'][0];

        // The conversation is still anchored where it started...
        $this->assertSame($participant->thread_attendee_id, $row['id']);
        // ...while the row reports the video they are actually on.
        $this->assertSame($this->numbers->id, $row['room']);

        $this->assertSame('Homeowner walkthrough', $row['flow']['funnel']);
        $this->assertSame('The numbers', $row['flow']['step']);
        $this->assertSame('Show me the numbers', $row['flow']['chose']);
        $this->assertSame(2, $row['flow']['seen']);
    }

    public function test_the_steps_of_a_flow_are_one_thing_in_the_console(): void
    {
        $this->enter();

        $rooms = $this->actingAs($this->alice)
            ->getJson('/member/presentations/live/feed')
            ->json('rooms');

        $steps = collect($rooms)->filter(fn ($r) => $r['funnel'] !== null);

        // Every step still arrives, tagged with the flow it belongs to, so the
        // page can gather them into one chip rather than six.
        $this->assertCount(3, $steps);
        $this->assertSame(
            [$this->funnel->id],
            $steps->pluck('funnel.id')->unique()->values()->all(),
        );
        $this->assertSame('Homeowner walkthrough', $steps->first()['funnel']['title']);
    }

    public function test_a_member_is_told_what_their_prospect_picked(): void
    {
        $participant = $this->enter();
        $cue         = $this->branch($this->intro, $this->numbers, 'Show me the numbers');

        $notes = $this->app->instance(NotificationService::class, new FakeNotifications);

        $this->withCredentials()
            ->withCookie('pres_'.$this->intro->id, $participant->threadAttendee->token)
            ->postJson('/watch/'.$this->intro->slug.'/choose', ['cue' => $cue->id]);

        $notification = $notes->lastFor($this->alice);

        $this->assertNotNull($notification);
        // A choice is an opening line; "they are still watching" is not.
        $this->assertStringContainsString('Casey', $notification['title']);
        $this->assertStringContainsString('Show me the numbers', $notification['title']);
        $this->assertStringContainsString('guest='.$participant->thread_attendee_id, $notification['url']);
    }

    public function test_two_members_inviting_the_same_person_are_two_journeys(): void
    {
        $this->enter();

        $this->post('/flow/'.$this->funnel->slug.'/register', [
            'name'  => 'Casey',
            'email' => 'casey@example.com',
            'code'  => $this->bob->referral_code,
        ])->assertRedirect();

        // Two relationships, not one shared prospect. Same rule as attendees.
        $this->assertSame(2, FunnelParticipant::where('email', 'casey@example.com')->count());
        $this->assertSame(1, FunnelParticipant::visibleTo($this->alice)->count());
        $this->assertSame(1, FunnelParticipant::visibleTo($this->bob)->count());
    }
}
