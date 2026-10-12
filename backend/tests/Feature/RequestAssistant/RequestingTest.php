<?php

namespace Tests\Feature\RequestAssistant;

use App\Models\ChangeRequest;
use App\Models\ChangeRequestMessage;
use App\Models\Role;
use App\Services\RequestAssistant\TurnService;

/** The live site: super admins make requests, which are kept here. */
class RequestingTest extends RequestAssistantTestCase
{
    public function test_only_super_admins_have_it(): void
    {
        foreach ([Role::SUPPORT_ADMIN, Role::PAID_MEMBER] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->get('/admin/change-requests')->assertForbidden();
            $this->actingAs($user)->post('/admin/change-requests', ['title' => 'x', 'body' => 'y'])->assertForbidden();
        }
        $this->assertSame(0, ChangeRequest::count());

        $this->actingAs($this->michael)->get('/admin/change-requests')->assertOk()->assertSee('Suggest a change');
    }

    public function test_the_header_button_is_for_super_admins_only(): void
    {
        $this->actingAs($this->michael)->get('/admin/dashboard')->assertOk()->assertSee('requestAssistantModal', false);
        $this->actingAs($this->user(Role::SUPPORT_ADMIN))->get('/admin/dashboard')->assertOk()->assertDontSee('requestAssistantModal', false);
    }

    public function test_the_button_is_on_member_pages_for_a_super_admin_and_never_for_a_member(): void
    {
        $this->actingAs($this->michael)->get('/member/dashboard')
            ->assertOk()->assertSee('requestAssistantModal', false)->assertSee(route('admin.change-requests.store'), false);
        $this->actingAs($this->user(Role::PAID_MEMBER))->get('/member/dashboard')
            ->assertOk()->assertDontSee('requestAssistantModal', false);
    }

    public function test_pages_without_a_header_get_a_floating_button_for_super_admins_only(): void
    {
        $this->actingAs($this->michael);
        $this->assertStringContainsString('Suggest a change to this page', view('partials.request-assistant-floating')->render());

        $this->actingAs($this->user(Role::PAID_MEMBER));
        $this->assertStringNotContainsString('Suggest a change', view('partials.request-assistant-floating')->render());
    }

    public function test_starting_a_request_from_a_page_keeps_it_here_and_waits_for_dev(): void
    {
        $this->actingAs($this->michael)->post('/admin/change-requests', [
            'title' => 'Phone column', 'body' => 'Add phones to this list.',
            'source_url' => 'https://app.q3.life/admin/users?search=bob&token=secret', 'source_title' => 'Users',
        ])->assertRedirect();

        $request = ChangeRequest::sole();
        $this->assertSame('drafting', $request->status);
        $this->assertSame(TurnService::MODE_REPLY, $request->assistant_pending);
        $this->assertTrue($request->isAssistantBusy());
        $this->assertSame('https://app.q3.life/admin/users', $request->source_url, 'the query string is dropped');

        $this->actingAs($this->michael)->get("/admin/change-requests/{$request->id}")
            ->assertOk()->assertSee('Started from')->assertSee('looking through the project');
    }

    public function test_every_super_admin_sees_every_request_but_only_its_author_continues_it(): void
    {
        $request = $this->openRequest();
        $other = $this->user(Role::SUPER_ADMIN, 'Owner');

        $this->actingAs($other)->get('/admin/change-requests')->assertOk()->assertSee('Team export');
        $this->actingAs($other)->get("/admin/change-requests/{$request->id}")->assertOk()->assertSee('Only they can continue');
        $this->actingAs($other)->post("/admin/change-requests/{$request->id}/message", ['body' => 'Hijack'])
            ->assertSessionHas('error');
        $this->assertSame(1, $request->messages()->count());
    }

    public function test_no_second_message_while_the_assistant_is_answering(): void
    {
        $request = $this->openRequest(['assistant_busy_since' => now(), 'assistant_pending' => 'reply']);

        $this->actingAs($this->michael)->post("/admin/change-requests/{$request->id}/message", ['body' => 'More'])
            ->assertSessionHas('error');
        $this->assertSame(1, $request->messages()->count());
    }

    public function test_a_turn_dev_never_answered_stops_blocking_after_a_while(): void
    {
        $request = $this->openRequest(['assistant_busy_since' => now()->subMinutes(ChangeRequest::BUSY_STALE_MINUTES + 1)]);

        $this->assertFalse($request->isAssistantBusy());
    }

    public function test_the_hourly_limit(): void
    {
        config(['request-assistant.hourly_limit' => 2]);
        $this->openRequest();
        $this->openRequest();

        $this->actingAs($this->michael)->post('/admin/change-requests', ['title' => 'Third', 'body' => 'x'])->assertSessionHas('error');
        $this->assertSame(2, ChangeRequest::count());
    }

    public function test_a_plan_is_needed_before_submitting(): void
    {
        $request = $this->openRequest();

        $this->actingAs($this->michael)->post("/admin/change-requests/{$request->id}/submit")->assertSessionHas('error');
        $this->assertSame('drafting', $request->fresh()->status);

        $request->update(['plan' => "## Summary\nExport."]);
        $this->actingAs($this->michael)->post("/admin/change-requests/{$request->id}/submit");
        $this->assertSame('submitted', $request->fresh()->status);
    }

    public function test_drafting_the_plan_waits_for_dev(): void
    {
        $request = $this->openRequest();

        $this->actingAs($this->michael)->post("/admin/change-requests/{$request->id}/plan");

        $this->assertSame(TurnService::MODE_PLAN, $request->fresh()->assistant_pending);
    }

    public function test_a_decided_request_is_closed_and_shows_the_decision(): void
    {
        $request = $this->openRequest(['status' => 'approved', 'reviewer_name' => 'Owner', 'reviewed_at' => now(), 'review_notes' => 'Next sprint.']);

        $this->actingAs($this->michael)->post("/admin/change-requests/{$request->id}/message", ['body' => 'More'])->assertSessionHas('error');
        $this->actingAs($this->michael)->get("/admin/change-requests/{$request->id}")
            ->assertOk()->assertSee('by Owner')->assertSee('Next sprint.')->assertDontSee('Save decision');
    }

    public function test_there_is_no_review_screen_here(): void
    {
        $this->actingAs($this->michael)->get('/admin/review-requests')->assertNotFound();
    }

    public function test_assistant_text_is_markdown_with_html_escaped(): void
    {
        $request = $this->openRequest(['plan' => "## Summary\n<img src=x onerror=alert(1)>"]);
        $request->messages()->create([
            'role' => ChangeRequestMessage::ROLE_ASSISTANT,
            'body' => '**Bold** <script>alert(1)</script> [x](javascript:alert(1))',
        ]);

        $this->actingAs($this->michael)->get("/admin/change-requests/{$request->id}")
            ->assertOk()
            ->assertSee('<strong>Bold</strong>', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x', false)
            ->assertDontSee('href="javascript:', false);
    }
}
