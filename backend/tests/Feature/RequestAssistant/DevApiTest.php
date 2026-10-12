<?php

namespace Tests\Feature\RequestAssistant;

use App\Models\ChangeRequest;
use App\Models\ChangeRequestMessage;

/** The live site's API, which only dev calls: relay turns and review. */
class DevApiTest extends RequestAssistantTestCase
{
    private function api(): static
    {
        return $this->withToken(self::TOKEN);
    }

    public function test_there_is_no_api_without_the_token(): void
    {
        $this->postJson('/api/request-assistant/relay/claim', ['commit' => self::COMMIT])->assertNotFound();
        $this->api()->postJson('/api/request-assistant/relay/claim', ['commit' => self::COMMIT])->assertNotFound(); // none issued

        $this->issueToken();
        $this->withToken('wrong')->getJson('/api/request-assistant/review/requests')->assertNotFound();
        $this->api()->getJson('/api/request-assistant/review/requests')->assertOk();
    }

    public function test_a_waiting_turn_is_handed_out_once_with_its_prompts(): void
    {
        $this->issueToken();
        $request = $this->openRequest(['assistant_pending' => 'reply', 'assistant_busy_since' => now(), 'source_url' => 'https://app.q3.life/admin/users']);

        $turn = $this->api()->postJson('/api/request-assistant/relay/claim', ['commit' => self::COMMIT])->assertOk()->json('turn');
        $this->assertSame($request->id, $turn['id']);
        $this->assertSame('reply', $turn['mode']);
        $this->assertNull($turn['session_id']);
        $this->assertStringContainsString('Partners want to download their team list.', $turn['prompt']);
        $this->assertStringContainsString('/admin/users', $turn['prompt']);

        $this->api()->postJson('/api/request-assistant/relay/claim', ['commit' => self::COMMIT])->assertJsonPath('turn', null);

        // Dev took it and went away: offered again once it is stale.
        $this->travel(ChangeRequest::BUSY_STALE_MINUTES + 1)->minutes();
        $this->api()->postJson('/api/request-assistant/relay/claim', ['commit' => self::COMMIT])->assertJsonPath('turn.id', $request->id);
    }

    public function test_the_answer_is_recorded_and_the_conversation_freed(): void
    {
        $this->issueToken();
        $request = $this->openRequest(['assistant_pending' => 'reply', 'assistant_busy_since' => now()]);
        $this->api()->postJson('/api/request-assistant/relay/claim', ['commit' => self::COMMIT]);

        $this->api()->postJson("/api/request-assistant/relay/{$request->id}/complete", [
            'mode' => 'reply', 'text' => 'Who should download it?', 'commit' => self::COMMIT,
            'session_id' => '6f9619ff-8b86-d011-b42d-00cf4fc964ff', 'meta' => ['cost_usd' => 0.2, 'turns' => 5],
        ])->assertOk();

        $request->refresh();
        $this->assertFalse($request->isAssistantBusy());
        $this->assertNull($request->assistant_pending);
        $this->assertSame('6f9619ff-8b86-d011-b42d-00cf4fc964ff', $request->claude_session_id);
        $this->assertSame(self::COMMIT, $request->snapshot_commit);
        $this->assertSame('Who should download it?', $request->messages()->get()->last()->body);

        // A late duplicate is dropped.
        $this->api()->postJson("/api/request-assistant/relay/{$request->id}/complete", [
            'mode' => 'reply', 'text' => 'again', 'commit' => self::COMMIT,
        ])->assertStatus(409);
        $this->assertSame(2, $request->messages()->count());
    }

    public function test_a_drafted_plan_is_stored_without_its_preamble(): void
    {
        $this->issueToken();
        $request = $this->openRequest(['assistant_pending' => 'plan', 'assistant_busy_since' => now(), 'claude_session_id' => '6f9619ff-8b86-d011-b42d-00cf4fc964ff']);
        $turn = $this->api()->postJson('/api/request-assistant/relay/claim', ['commit' => self::COMMIT])->json('turn');
        $this->assertStringContainsString('## Summary', $turn['prompt']);
        $this->assertStringContainsString('Partners want', $turn['fresh_prompt'], 'the fallback carries the conversation');

        $this->api()->postJson("/api/request-assistant/relay/{$request->id}/complete", [
            'mode' => 'plan', 'text' => "Here is the plan.\n\n---\n## Summary\nA CSV export.", 'commit' => self::COMMIT,
        ])->assertOk();

        $this->assertSame("## Summary\nA CSV export.", $request->fresh()->plan);
    }

    public function test_a_failure_is_shown_to_the_requester(): void
    {
        $this->issueToken();
        $request = $this->openRequest(['assistant_pending' => 'reply', 'assistant_busy_since' => now()]);

        $this->api()->postJson("/api/request-assistant/relay/{$request->id}/fail", ['error' => 'CLI not logged in'])->assertOk();

        $request->refresh();
        $this->assertFalse($request->isAssistantBusy());
        $last = $request->messages()->get()->last();
        $this->assertSame(ChangeRequestMessage::ROLE_ERROR, $last->role);
        $this->assertSame('CLI not logged in', $last->meta['error']);
    }

    public function test_review_reads_and_decides_requests_kept_here(): void
    {
        $this->issueToken();
        $request = $this->openRequest(['status' => 'submitted', 'plan' => '## Summary']);

        $this->api()->getJson('/api/request-assistant/review/requests')
            ->assertOk()->assertJsonPath('requests.0.title', 'Team export')->assertJsonPath('requests.0.requester_name', 'Michael Stern');
        $this->api()->getJson("/api/request-assistant/review/requests/{$request->id}")
            ->assertOk()->assertJsonPath('request.plan', '## Summary');

        $this->api()->patchJson("/api/request-assistant/review/requests/{$request->id}", [
            'status' => 'approved', 'review_notes' => 'Good.', 'reviewer_name' => 'Owner',
        ])->assertOk();
        $this->api()->postJson("/api/request-assistant/review/requests/{$request->id}/comment", [
            'body' => 'Do it after launch.', 'author_name' => 'Owner',
        ])->assertOk();

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame('Owner', $request->reviewerLabel());
        $this->assertSame('Owner (reviewer)', $request->messages()->get()->last()->authorLabel());

        $this->api()->patchJson("/api/request-assistant/review/requests/{$request->id}", [
            'status' => 'drafting', 'reviewer_name' => 'Owner',
        ])->assertUnprocessable();
    }
}
