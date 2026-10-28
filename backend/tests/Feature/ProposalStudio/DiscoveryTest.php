<?php

namespace Tests\Feature\ProposalStudio;

use App\Models\Prospect;
use App\Models\ProspectMessage;
use App\Models\Role;
use App\Models\StudioJob;
use App\Services\ProposalStudio\ClaudeRunner;
use App\Services\ProposalStudio\JobQueue;
use App\Services\ProposalStudio\Jobs\ProspectTurn;
use RuntimeException;

/** Phase 0 and 2: the prospect workspace, its conversation and drafts, and the job queue under it. */
class DiscoveryTest extends StudioTestCase
{
    public function test_it_is_hidden_unless_switched_on_and_is_for_super_admins_only(): void
    {
        $this->actingAs($this->super)->get('/admin/proposal-studio')->assertOk()->assertSee('No prospects yet.');
        $this->actingAs($this->user(Role::SUPPORT_ADMIN))->get('/admin/proposal-studio')->assertForbidden();

        config(['proposal-studio.enabled' => false]);
        $this->actingAs($this->super)->get('/admin/proposal-studio')->assertNotFound();
    }

    public function test_a_new_prospect_queues_a_reply_and_takes_one_turn_at_a_time(): void
    {
        $this->actingAs($this->super)->post('/admin/proposal-studio/prospects', [
            'offering_id' => $this->plasmaguard->id,
            'company_name' => 'Bright Smile Dental',
            'intake' => 'Four-chair practice.',
        ])->assertRedirect();
        $prospect = Prospect::firstOrFail();

        $this->assertTrue($prospect->isAssistantBusy());
        $this->assertSame(JobQueue::PROSPECT_REPLY, $prospect->openJob()?->kind);

        $this->post("/admin/proposal-studio/prospects/{$prospect->id}/message", ['body' => 'Also a second office.'])->assertSessionHas('error');
        $this->assertSame(1, $prospect->messages()->count());
    }

    public function test_the_worker_records_the_reply_and_the_profile_it_carried(): void
    {
        $prospect = $this->prospect();
        $this->runnerAnswering(<<<'TXT'
A small practice like this is a good fit. What HVAC do they have?

```profile
{"summary": "Four-chair dental practice.", "facts": [{"label": "Size", "value": "2,400 sq ft", "basis": "stated"}, {"label": "", "value": "dropped"}], "sites": [{"name": "Main office", "sq_ft": "2400"}], "open_questions": ["What HVAC system?"], "vendor_questions": []}
```
TXT);
        $this->work();

        $prospect->refresh();
        $this->assertFalse($prospect->isAssistantBusy());
        $reply = $prospect->messages()->get()->last();
        $this->assertSame(ProspectMessage::ROLE_ASSISTANT, $reply->role);
        $this->assertSame('A small practice like this is a good fit. What HVAC do they have?', $reply->body);
        $this->assertSame('Four-chair dental practice.', $prospect->profile['summary']);
        $this->assertCount(1, $prospect->profile['facts']);

        $this->actingAs($this->super)->get("/admin/proposal-studio/prospects/{$prospect->id}")
            ->assertOk()->assertSee('Main office')->assertSee('What HVAC system?');
    }

    public function test_a_draft_starts_at_its_first_heading_and_a_bad_profile_keeps_the_old_one(): void
    {
        $prospect = $this->prospect();
        $this->runnerAnswering("Reply.\n```profile\n{\"summary\": \"Kept.\"}\n```", "Here you go.\n\n## Recommended plan\nOne unit.\n\n```profile\n{not json\n```");
        $this->work();

        $this->actingAs($this->super)->post("/admin/proposal-studio/prospects/{$prospect->id}/draft")->assertRedirect();
        $this->work();

        $prospect->refresh();
        $this->assertSame("## Recommended plan\nOne unit.", $prospect->draft);
        $this->assertSame('Kept.', $prospect->profile['summary']);
    }

    public function test_a_failed_turn_is_shown_and_frees_the_conversation(): void
    {
        $prospect = $this->prospect();
        $runner = $this->createMock(ClaudeRunner::class);
        $runner->method('run')->willThrowException(new RuntimeException('rate limited'));
        $this->app->instance(ClaudeRunner::class, $runner);

        $this->work();

        $prospect->refresh();
        $this->assertFalse($prospect->isAssistantBusy());
        $this->assertSame(ProspectMessage::ROLE_ERROR, $prospect->messages()->get()->last()->role);
        $this->assertSame(StudioJob::FAILED, StudioJob::first()->status);
    }

    public function test_a_job_taken_by_a_worker_that_died_is_offered_again_then_given_up(): void
    {
        $prospect = $this->prospect();
        $queue = app(JobQueue::class);

        for ($attempt = 1; $attempt <= JobQueue::MAX_ATTEMPTS; $attempt++) {
            $this->assertSame($prospect->id, $queue->claim()['job']->subject_id ?? null, "attempt {$attempt}");
            $this->assertNull($queue->claim());
            $this->travel(StudioJob::STALE_MINUTES + 1)->minutes();
        }

        $this->assertNull($queue->claim());
        $this->assertSame(StudioJob::FAILED, StudioJob::first()->status);
        $this->assertFalse($prospect->fresh()->isAssistantBusy());
    }

    public function test_split_profile_leaves_text_without_a_block_alone(): void
    {
        $this->assertSame(['Just text.', null], ProspectTurn::splitProfile("Just text.\n"));
    }
}
