<?php

namespace Tests\Feature\RequestAssistant;

use App\Models\ChangeRequest;
use App\Models\Role;
use App\Models\SupportAssist;
use App\Models\SupportAssistMessage;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\User;
use App\Services\RequestAssistant\ClaudeRunner;
use App\Services\RequestAssistant\Upstream;
use App\Services\RequestAssistant\Workspace;
use App\Services\SupportAssistant\Prompts;
use App\Services\SupportAssistant\TurnService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Mockery;

/** The Q3-AI Assistant on support tickets, answered by the Request Assistant's relay. */
class SupportAssistantTest extends RequestAssistantTestCase
{
    private const LIVE = 'https://live.example.com';

    private const ANSWER = <<<'MD'
    Let me look.

    ## Verdict
    **NOT_BUILT** — there is no team export today.

    ## What the system does today
    Partners can see their team on the Team page, but cannot download it.

    ## Draft reply
    Hi {name},

    Thanks for asking. There's no download yet, and I've passed it on to the team.

    ## Change needed
    A CSV export of a partner's team on the Team page.
    MD;

    private User $support;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->support = $this->user(Role::SUPPORT_ADMIN, 'Sam Support');
        $this->member = $this->user(Role::PAID_MEMBER, 'Jordan Rivera');
    }

    private function ticket(): SupportTicket
    {
        $ticket = SupportTicket::create([
            'user_id' => $this->member->id,
            'subject' => 'Jordan needs a team export',
            'category' => 'technical',
            'priority' => 'normal',
            'status' => 'open',
            'source_url' => 'https://app.q3.life/team?token=secret123',
        ]);
        SupportTicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->member->id,
            'body' => "Can I download my team? Call me on 555-123-4567 or jordan.rivera@example.com.\nThanks, Jordan Rivera",
        ]);

        return $ticket;
    }

    private function answered(SupportTicket $ticket, string $text = self::ANSWER): SupportAssist
    {
        $assist = SupportAssist::create(['ticket_id' => $ticket->id]);
        $assist->messages()->create(['role' => 'user', 'user_id' => $this->support->id, 'body' => 'Draft a reply']);
        $assist->messages()->create(['role' => 'assistant', 'body' => $text, 'meta' => TurnService::parse($text)]);

        return $assist;
    }

    // ── On the ticket page ────────────────────────────────────────────────

    public function test_the_panel_shows_only_where_a_relay_will_answer(): void
    {
        $ticket = $this->ticket();
        $this->actingAs($this->support)->get(route('admin.support.show', $ticket))->assertOk()->assertDontSee('Q3-AI Assistant');
        $this->actingAs($this->support)->post(route('admin.support.assist', $ticket))->assertSessionHas('error');
        $this->assertSame(0, SupportAssist::count());

        $this->issueToken();
        $this->actingAs($this->support)->get(route('admin.support.show', $ticket))->assertOk()->assertSee('Ask Q3-AI for a draft');
    }

    public function test_support_staff_ask_and_one_turn_waits(): void
    {
        $this->issueToken();
        $ticket = $this->ticket();

        $this->actingAs($this->support)->post(route('admin.support.assist', $ticket), ['note' => 'They are on the Wait option.'])->assertRedirect();

        $assist = $ticket->assist()->first();
        $this->assertSame('reply', $assist->pending);
        $this->assertTrue($assist->isBusy());
        $this->assertSame('They are on the Wait option.', $assist->messages()->first()->body);
        $this->actingAs($this->support)->getJson(route('admin.support.assist.status', $ticket))->assertJsonPath('busy', true);

        // Not again until it has answered.
        $this->actingAs($this->support)->post(route('admin.support.assist', $ticket))->assertSessionHas('error');
        $this->assertSame(1, $assist->messages()->count());
    }

    public function test_the_member_s_identity_never_reaches_the_prompt(): void
    {
        $ticket = $this->ticket();
        $assist = SupportAssist::create(['ticket_id' => $ticket->id, 'pending' => 'reply', 'busy_since' => now()]);
        $assist->messages()->create(['role' => 'user', 'user_id' => $this->support->id, 'body' => 'Jordan says it is urgent.']);

        $prompt = Prompts::turn($assist->fresh(), true, null, self::COMMIT);

        foreach (['Jordan', 'Rivera', 'jordan.rivera@example.com', '555-123-4567', 'secret123', 'Sam Support'] as $private) {
            $this->assertStringNotContainsString($private, $prompt);
        }
        $this->assertStringContainsString('Can I download my team?', $prompt);
        $this->assertStringContainsString('[member] needs a team export', $prompt);
        $this->assertStringContainsString('[email]', $prompt);
        $this->assertStringContainsString('[number]', $prompt);
        $this->assertStringContainsString('Sent from the page: /team', $prompt);
        $this->assertStringContainsString('[Support staff]', $prompt);
    }

    public function test_the_answer_shows_its_verdict_and_a_draft_with_the_name_filled_in_on_the_page(): void
    {
        $this->issueToken();
        $ticket = $this->ticket();
        $this->answered($ticket);

        $this->actingAs($this->support)->get(route('admin.support.show', $ticket))->assertOk()
            ->assertSee('Needs something not built yet')
            ->assertSee('there is no team export today.')
            ->assertSee('Use this draft')
            ->assertSee('data-name="Jordan"', false)
            ->assertSee('A CSV export of a partner')
            // Support admins see the finding but cannot start a change request.
            ->assertDontSee('Start a change request')
            ->assertSee('Let a super admin know');

        $this->actingAs($this->support)->post(route('admin.support.assist.change-request', $ticket))->assertForbidden();
    }

    public function test_answers_are_parsed_into_verdict_and_sections(): void
    {
        $parsed = TurnService::parse(self::ANSWER);
        $this->assertSame('not_built', $parsed['verdict']);
        $this->assertSame('there is no team export today.', $parsed['verdict_reason']);
        $this->assertStringStartsWith('Hi {name},', $parsed['sections']['draft']);
        $this->assertSame('A CSV export of a partner\'s team on the Team page.', $parsed['sections']['change']);

        $this->assertSame(['sections' => []], TurnService::parse('Just some text.'));
    }

    public function test_a_super_admin_turns_a_not_built_finding_into_one_change_request(): void
    {
        $this->issueToken();
        $ticket = $this->ticket();
        $assist = $this->answered($ticket);

        $this->actingAs($this->michael)->get(route('admin.support.show', $ticket))->assertSee('Start a change request');
        $response = $this->actingAs($this->michael)->post(route('admin.support.assist.change-request', $ticket));

        $changeRequest = ChangeRequest::sole();
        $response->assertRedirect(route('admin.change-requests.show', $changeRequest));
        $this->assertSame($changeRequest->id, $assist->fresh()->change_request_id);
        $this->assertSame("{$ticket->ticket_number}: [member] needs a team export", $changeRequest->title);
        $this->assertSame('reply', $changeRequest->assistant_pending, 'the Request Assistant starts on it');
        $body = $changeRequest->messages()->first()->body;
        $this->assertStringContainsString('A CSV export', $body);
        $this->assertStringNotContainsString('Jordan', $body);

        // Pressing it again opens the same one.
        $this->actingAs($this->michael)->post(route('admin.support.assist.change-request', $ticket))
            ->assertRedirect(route('admin.change-requests.show', $changeRequest));
        $this->assertSame(1, ChangeRequest::count());
    }

    public function test_only_a_not_built_finding_becomes_a_change_request(): void
    {
        $ticket = $this->ticket();
        $this->answered($ticket, str_replace('NOT_BUILT', 'ANSWERABLE', self::ANSWER));

        $this->actingAs($this->michael)->post(route('admin.support.assist.change-request', $ticket))->assertSessionHas('error');
        $this->assertSame(0, ChangeRequest::count());
    }

    // ── The API dev calls ─────────────────────────────────────────────────

    public function test_support_turns_go_only_to_a_relay_that_asks_for_them(): void
    {
        $this->issueToken();
        $assist = SupportAssist::create(['ticket_id' => $this->ticket()->id, 'pending' => 'reply', 'busy_since' => now()]);
        $assist->messages()->create(['role' => 'user', 'user_id' => $this->support->id, 'body' => 'Draft a reply']);

        // An older relay posts answers to relay/{id}: it must never get one.
        $this->withToken(self::TOKEN)->postJson('/api/request-assistant/relay/claim', ['commit' => self::COMMIT])->assertJsonPath('turn', null);

        $turn = $this->withToken(self::TOKEN)->postJson('/api/request-assistant/relay/claim', ['commit' => self::COMMIT, 'kinds' => ['support']])->json('turn');
        $this->assertSame('support', $turn['kind']);
        $this->assertSame($assist->id, $turn['id']);
        $this->assertStringNotContainsString('Jordan', $turn['prompt']);

        $this->withToken(self::TOKEN)->postJson("/api/request-assistant/relay/support/{$assist->id}/complete", [
            'mode' => 'reply', 'text' => self::ANSWER, 'commit' => self::COMMIT, 'session_id' => '6f9619ff-8b86-d011-b42d-00cf4fc964ff',
        ])->assertOk();

        $assist->refresh();
        $this->assertNull($assist->pending);
        $this->assertFalse($assist->isBusy());
        $this->assertSame('not_built', $assist->messages()->get()->last()->verdict());

        $this->withToken(self::TOKEN)->postJson("/api/request-assistant/relay/support/{$assist->id}/complete", [
            'mode' => 'reply', 'text' => 'again', 'commit' => self::COMMIT,
        ])->assertStatus(409);
    }

    public function test_a_change_request_waiting_is_answered_before_support(): void
    {
        $this->issueToken();
        SupportAssist::create(['ticket_id' => $this->ticket()->id, 'pending' => 'reply', 'busy_since' => now()]);
        $request = $this->openRequest(['assistant_pending' => 'reply', 'assistant_busy_since' => now()]);

        $this->withToken(self::TOKEN)->postJson('/api/request-assistant/relay/claim', ['commit' => self::COMMIT, 'kinds' => ['support']])
            ->assertJsonPath('turn.id', $request->id)->assertJsonMissingPath('turn.kind');
    }

    // ── The relay on dev ──────────────────────────────────────────────────

    private function fakeCli(callable $answer): void
    {
        $workspace = Mockery::mock(Workspace::class);
        $workspace->shouldReceive('refresh')->andReturn(self::COMMIT);
        $this->app->instance(Workspace::class, $workspace);

        $runner = Mockery::mock(ClaudeRunner::class);
        $runner->shouldReceive('run')->andReturnUsing($answer);
        $this->app->instance(ClaudeRunner::class, $runner);
    }

    public function test_the_relay_answers_dev_s_own_tickets_with_the_support_role(): void
    {
        Upstream::connect(self::LIVE, self::TOKEN);
        Http::fake(['*' => Http::response([], 500)]);
        $assist = SupportAssist::create(['ticket_id' => $this->ticket()->id, 'pending' => 'reply', 'busy_since' => now()]);
        $assist->messages()->create(['role' => 'user', 'user_id' => $this->support->id, 'body' => 'Draft a reply']);

        $this->fakeCli(function (string $prompt, string $session, bool $resume, ?string $system) {
            $this->assertStringContainsString('Q3-AI Assistant for support', (string) $system);
            $this->assertStringNotContainsString('Rivera', $prompt);

            return ['text' => self::ANSWER, 'session_id' => $session, 'meta' => ['cost_usd' => 0.1]];
        });

        $this->artisan('request-assistant:relay --once')->assertSuccessful();

        Http::assertNothingSent();
        $assist->refresh();
        $this->assertNull($assist->pending);
        $this->assertNotNull($assist->claude_session_id);
        $this->assertSame(SupportAssistMessage::ROLE_ASSISTANT, $assist->messages()->get()->last()->role);
    }

    public function test_the_relay_posts_a_live_support_answer_to_the_support_path(): void
    {
        Upstream::connect(self::LIVE, self::TOKEN);
        Http::fake([
            self::LIVE.'/api/request-assistant/relay/claim' => Http::response(['turn' => [
                'kind' => 'support', 'id' => 9, 'number' => 'TKT-00009', 'mode' => 'reply', 'session_id' => null, 'prompt' => 'ticket', 'fresh_prompt' => 'ticket',
            ]]),
            self::LIVE.'/api/request-assistant/relay/*' => Http::response(['ok' => true]),
        ]);
        $this->fakeCli(fn (string $prompt, string $session) => ['text' => self::ANSWER, 'session_id' => $session, 'meta' => []]);

        $this->artisan('request-assistant:relay --once')->assertSuccessful();

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/relay/claim') && $r['kinds'] === ['support']);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/relay/support/9/complete') && $r['text'] === self::ANSWER);
    }
}
