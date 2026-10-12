<?php

namespace Tests\Feature\RequestAssistant;

use App\Models\ChangeRequest;
use App\Models\ChangeRequestMessage;
use App\Models\Role;
use App\Models\SiteSetting;
use App\Services\RequestAssistant\ClaudeRunner;
use App\Services\RequestAssistant\Payload;
use App\Services\RequestAssistant\Upstream;
use App\Services\RequestAssistant\Workspace;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;

/** Dev: the relay that answers on the CLI, and the review screens. */
class DevSideTest extends RequestAssistantTestCase
{
    private const LIVE = 'https://live.example.com';

    private function connect(): void
    {
        Upstream::connect(self::LIVE, self::TOKEN);
    }

    private function fakeWorkspace(): void
    {
        $workspace = Mockery::mock(Workspace::class);
        $workspace->shouldReceive('refresh')->andReturn(self::COMMIT);
        $this->app->instance(Workspace::class, $workspace);
    }

    private function fakeRunner(callable $answer): void
    {
        $runner = Mockery::mock(ClaudeRunner::class);
        $runner->shouldReceive('run')->andReturnUsing($answer);
        $this->app->instance(ClaudeRunner::class, $runner);
    }

    /** @param array<string, mixed>|null $turn */
    private function fakeLive(?array $turn): void
    {
        Http::fake([
            self::LIVE.'/api/request-assistant/relay/claim' => Http::response(['turn' => $turn]),
            self::LIVE.'/api/request-assistant/relay/*' => Http::response(['ok' => true]),
            '*' => Http::response([], 500),
        ]);
    }

    private function turn(?string $session = null): array
    {
        return ['id' => 7, 'number' => 'CR-0007', 'mode' => 'reply', 'session_id' => $session, 'prompt' => 'next message', 'fresh_prompt' => 'whole conversation'];
    }

    // ── Relay ─────────────────────────────────────────────────────────────

    public function test_the_relay_answers_a_waiting_turn_on_the_cli_and_posts_it_back(): void
    {
        $this->connect();
        $this->fakeWorkspace();
        $this->fakeLive($this->turn());
        $this->fakeRunner(function (string $prompt, string $session, bool $resume) {
            $this->assertSame('next message', $prompt);
            $this->assertFalse($resume);

            return ['text' => 'Who is it for?', 'session_id' => $session, 'meta' => ['cost_usd' => 0.1]];
        });

        $this->artisan('request-assistant:relay --once')->assertSuccessful();

        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::LIVE.'/api/request-assistant/relay/claim'
            && $r['commit'] === self::COMMIT && $r->hasHeader('Authorization', 'Bearer '.self::TOKEN));
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/relay/7/complete')
            && $r['text'] === 'Who is it for?' && $r['mode'] === 'reply' && $r['commit'] === self::COMMIT);
    }

    public function test_when_the_session_cannot_be_resumed_it_starts_again_from_the_live_record(): void
    {
        $this->connect();
        $this->fakeWorkspace();
        $this->fakeLive($this->turn('6f9619ff-8b86-d011-b42d-00cf4fc964ff'));
        $calls = [];
        $this->fakeRunner(function (string $prompt, string $session, bool $resume) use (&$calls) {
            $calls[] = [$prompt, $resume];
            if ($resume) {
                throw new RuntimeException('No conversation found');
            }

            return ['text' => 'Back on track.', 'session_id' => $session, 'meta' => []];
        });

        $this->artisan('request-assistant:relay --once')->assertSuccessful();

        $this->assertSame([['next message', true], ['whole conversation', false]], $calls);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/complete') && $r['text'] === 'Back on track.');
    }

    public function test_a_cli_failure_is_reported_to_the_live_site(): void
    {
        $this->connect();
        $this->fakeWorkspace();
        $this->fakeLive($this->turn());
        $this->fakeRunner(fn () => throw new RuntimeException('CLI not logged in'));

        $this->artisan('request-assistant:relay --once')->assertSuccessful();

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/relay/7/fail') && $r['error'] === 'CLI not logged in');
        Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/complete'));
    }

    public function test_nothing_waiting_means_nothing_runs(): void
    {
        $this->connect();
        $this->fakeWorkspace();
        $this->fakeLive(null);
        $this->fakeRunner(fn () => $this->fail('the CLI must not run'));

        $this->artisan('request-assistant:relay --once')->assertSuccessful();
    }

    public function test_the_token_is_stored_encrypted(): void
    {
        $this->connect();

        $stored = (string) SiteSetting::get(Upstream::SETTING);
        $this->assertStringNotContainsString(self::TOKEN, $stored);
        $this->assertSame(['url' => self::LIVE, 'token' => self::TOKEN], Upstream::config());
    }

    // ── Review screens ────────────────────────────────────────────────────

    public function test_review_exists_only_once_connected_and_only_for_super_admins(): void
    {
        $this->actingAs($this->michael)->get('/admin/review-requests')->assertNotFound();

        $this->connect();
        Http::fake(['*' => Http::response(['requests' => [], 'counts' => []])]);
        $this->actingAs($this->user(Role::SUPPORT_ADMIN))->get('/admin/review-requests')->assertForbidden();
        $this->actingAs($this->michael)->get('/admin/review-requests')->assertOk()->assertSee('live.example.com');
    }

    public function test_a_request_on_the_live_site_is_read_and_decided_from_here(): void
    {
        // What the live site would send, from its own serializer.
        $live = $this->openRequest(['status' => 'submitted', 'plan' => "## Summary\nA CSV export.", 'source_url' => 'https://app.q3.life/admin/users']);
        $live->messages()->create(['role' => ChangeRequestMessage::ROLE_ASSISTANT, 'body' => 'Which **list**?', 'meta' => ['cost_usd' => 0.25, 'turns' => 4]]);
        $payload = Payload::full($live->fresh()->load('user', 'reviewer', 'messages.user'));
        ChangeRequest::query()->delete();

        $this->connect();
        Http::fake([
            self::LIVE."/api/request-assistant/review/requests/{$live->id}" => Http::response(['request' => $payload]),
            '*' => Http::response(['ok' => true]),
        ]);
        $owner = $this->user(Role::SUPER_ADMIN, 'Owner');

        $this->actingAs($owner)->get("/admin/review-requests/{$live->id}")
            ->assertOk()->assertSee('Team export')->assertSee('Michael Stern')->assertSee('<strong>list</strong>', false)
            ->assertSee('Save decision')->assertSee('/admin/users');

        $this->actingAs($owner)->patch("/admin/review-requests/{$live->id}/review", ['status' => 'approved', 'review_notes' => 'Go.'])
            ->assertRedirect("/admin/review-requests/{$live->id}");
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PATCH' && $r['status'] === 'approved' && $r['reviewer_name'] === 'Owner');

        $this->actingAs($owner)->get("/admin/review-requests/{$live->id}/export")
            ->assertOk()->assertSee('A CSV export.')->assertSee('Michael Stern');

        $this->assertSame(0, ChangeRequest::count(), 'dev keeps no copy');
    }

    public function test_when_the_live_site_is_down_review_says_so(): void
    {
        $this->connect();
        Http::fake(['*' => Http::response('Bad gateway', 502)]);

        $this->actingAs($this->michael)->get('/admin/review-requests')->assertOk()->assertSee('answered 502');
    }

    // ── The CLI call ──────────────────────────────────────────────────────

    public function test_the_cli_is_started_with_every_guardrail(): void
    {
        config(['request-assistant.workspace' => '/srv/example/workspace']);
        $command = app(ClaudeRunner::class)->command('6f9619ff-8b86-d011-b42d-00cf4fc964ff', false);
        $flag = fn (string $name) => $command[array_search($name, $command, true) + 1];

        $this->assertSame(['/usr/bin/env', '-i'], array_slice($command, 0, 2));
        $this->assertSame('Read,Grep,Glob', $flag('--tools'));
        $this->assertSame('dontAsk', $flag('--permission-mode'));
        $this->assertSame('', $flag('--setting-sources'));
        $this->assertContains('--strict-mcp-config', $command);
        $this->assertContains('CLAUDE_CODE_DISABLE_AUTO_MEMORY=1', $command);
        $this->assertNotContains('--dangerously-skip-permissions', $command);
        $this->assertNotContains('--bare', $command, '--bare skips hooks, which would switch the guard off');

        $hook = json_decode($flag('--settings'), true)['hooks']['PreToolUse'][0];
        $this->assertSame('*', $hook['matcher']);
        $this->assertStringContainsString("request-assistant-guard.php' '/srv/example/workspace'", $hook['hooks'][0]['command']);

        // Nothing from the worker's environment rides along, and the
        // subscription login pays, never an API key.
        foreach ($command as $part) {
            $this->assertDoesNotMatchRegularExpression('/^(DB_|STRIPE|APP_KEY|AWS_|RESEND)/', $part);
            $this->assertStringNotContainsString('ANTHROPIC_API_KEY', $part);
        }
    }
}
