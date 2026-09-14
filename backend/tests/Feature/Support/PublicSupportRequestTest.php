<?php

namespace Tests\Feature\Support;

use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\User;
use App\Services\TurnstileVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The public website contact form (POST /api/support/requests) and how the
 * resulting anonymous tickets show up for staff and (not) for members.
 */
class PublicSupportRequestTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/support/requests';

    protected function setUp(): void
    {
        parent::setUp();

        // The site posts same-origin from a Sanctum stateful domain. Pin that
        // here so the test does not depend on .env.
        config()->set('sanctum.stateful', ['q3.onlinebros.com']);

        // Turnstile configured, no hostname allowlist. Cloudflare is never
        // really called: every test that expects a call fakes it.
        config()->set('services.turnstile.secret_key', 'test-secret');
        config()->set('services.turnstile.allowed_hostnames', []);
        Http::preventStrayRequests();

        Role::firstOrCreate(['name' => Role::FREE_MEMBER],
            ['display_name' => 'Free Member', 'is_admin' => false, 'level' => 1]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Visitor',
            'email' => 'visitor@example.test',
            'topic' => 'partner_program',
            'subject' => 'How does the partner program work?',
            'message' => "Hello,\nI would like to know more.",
            'page_url' => 'https://q3.onlinebros.com/site/contact',
            'hp_field' => '',
            'turnstile_token' => 'XXXX.DUMMY.TOKEN.XXXX',
        ], $overrides);
    }

    /** Fake Cloudflare siteverify. Call once per test (first matching fake wins). */
    private function fakeTurnstile(array $body = ['success' => true, 'hostname' => 'q3.life'], int $status = 200): void
    {
        Http::fake([TurnstileVerifier::VERIFY_URL => Http::response($body, $status)]);
    }

    private function assertNothingStored(): void
    {
        $this->assertDatabaseCount('support_tickets', 0);
        $this->assertDatabaseCount('support_ticket_replies', 0);
    }

    private function fromSite(): static
    {
        return $this->withHeaders([
            'Origin' => 'https://q3.onlinebros.com',
            'Referer' => 'https://q3.onlinebros.com/site/contact',
        ]);
    }

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => Role::SUPER_ADMIN],
            ['display_name' => 'Super Admin', 'is_admin' => true, 'level' => 100]);

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function websiteTicket(array $overrides = []): SupportTicket
    {
        $ticket = SupportTicket::create(array_merge([
            'user_id' => null,
            'channel' => SupportTicket::CHANNEL_WEBSITE,
            'requester_name' => 'Wendy Website',
            'requester_email' => 'wendy@example.test',
            'subject' => 'Question from the website',
            'category' => 'privacy',
            'priority' => 'normal',
            'status' => 'open',
        ], $overrides));

        SupportTicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => null,
            'body' => 'Please delete my data.',
        ]);

        return $ticket;
    }

    // ── The endpoint ──────────────────────────────────────────────────────────

    public function test_a_valid_request_creates_a_website_ticket_with_its_first_message(): void
    {
        // A member with the same address must NOT be linked: anyone can type it.
        User::factory()->create(['email' => 'visitor@example.test']);
        $this->fakeTurnstile();

        $response = $this->fromSite()->postJson(self::URL, $this->payload());

        Http::assertSent(fn (HttpRequest $request) => $request->url() === TurnstileVerifier::VERIFY_URL
            && $request['secret'] === 'test-secret'
            && $request['response'] === 'XXXX.DUMMY.TOKEN.XXXX'
            && $request['remoteip'] === '127.0.0.1');

        $ticket = SupportTicket::sole();

        $response->assertCreated()
            ->assertExactJson(['ok' => true, 'reference' => $ticket->ticket_number])
            // Stateful Sanctum pipeline (session + CSRF) did not run for this
            // same-origin request, so no CSRF cookie was issued.
            ->assertCookieMissing('XSRF-TOKEN');

        $this->assertNull($ticket->user_id);
        $this->assertSame('website', $ticket->channel);
        $this->assertSame('Test Visitor', $ticket->requester_name);
        $this->assertSame('visitor@example.test', $ticket->requester_email);
        $this->assertSame('partner_program', $ticket->category);
        $this->assertSame('normal', $ticket->priority);
        $this->assertSame('open', $ticket->status);
        $this->assertSame('https://q3.onlinebros.com/site/contact', $ticket->source_url);

        $reply = SupportTicketReply::sole();
        $this->assertSame($ticket->id, $reply->ticket_id);
        $this->assertNull($reply->user_id);
        $this->assertSame("Hello,\nI would like to know more.", $reply->body);
        $this->assertFalse($reply->is_internal);
    }

    public function test_page_url_is_optional_and_single_line_fields_are_normalised(): void
    {
        $payload = $this->payload(['name' => "  Test\n  Visitor  ", 'subject' => " Hi\r\nthere "]);
        unset($payload['page_url'], $payload['hp_field']);
        $this->fakeTurnstile();

        $this->postJson(self::URL, $payload)->assertCreated();

        $ticket = SupportTicket::sole();
        $this->assertSame('Test Visitor', $ticket->requester_name);
        $this->assertSame('Hi there', $ticket->subject);
        $this->assertNull($ticket->source_url);
    }

    public function test_missing_and_invalid_fields_return_422_with_field_errors(): void
    {
        $this->fakeTurnstile();

        $this->postJson(self::URL, [])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors'])
            ->assertJsonValidationErrors(['name', 'email', 'topic', 'subject', 'message', 'turnstile_token']);

        $this->postJson(self::URL, $this->payload([
            'email' => 'not-an-email',
            'name' => str_repeat('a', 121),
            'subject' => str_repeat('a', 201),
            'message' => str_repeat('a', 5001),
            'page_url' => 'javascript:alert(1)',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'name', 'subject', 'message', 'page_url']);

        // Validation runs before Turnstile: invalid input never reaches Cloudflare.
        Http::assertNothingSent();
        $this->assertNothingStored();
    }

    public function test_an_unknown_topic_is_rejected(): void
    {
        // 'training' is a member category but not a website topic.
        foreach (['training', 'sales', ''] as $topic) {
            $this->postJson(self::URL, $this->payload(['topic' => $topic]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['topic']);
        }

        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_the_honeypot_looks_like_success_and_stores_nothing(): void
    {
        $this->fakeTurnstile();

        $this->fromSite()->postJson(self::URL, $this->payload(['hp_field' => 'http://spam.example']))
            ->assertCreated()
            ->assertExactJson(['ok' => true, 'reference' => null]);

        Http::assertNothingSent();
        $this->assertNothingStored();
    }

    // ── Turnstile ─────────────────────────────────────────────────────────────

    private const TURNSTILE_ERROR = 'Please complete the verification check and try again.';

    private const UNAVAILABLE_BODY = [
        'ok' => false,
        'message' => "We couldn't verify your request right now. Please try again in a few minutes.",
    ];

    public function test_a_missing_turnstile_token_is_a_422(): void
    {
        $this->fakeTurnstile();
        $payload = $this->payload();
        unset($payload['turnstile_token']);

        $this->fromSite()->postJson(self::URL, $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['turnstile_token']);

        Http::assertNothingSent();
        $this->assertNothingStored();
    }

    public function test_a_token_cloudflare_rejects_is_a_422_and_stores_nothing(): void
    {
        $this->fakeTurnstile(['success' => false, 'error-codes' => ['invalid-input-response']]);

        $this->fromSite()->postJson(self::URL, $this->payload())
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors'])
            ->assertJsonPath('errors.turnstile_token', [self::TURNSTILE_ERROR]);

        Http::assertSentCount(1);
        $this->assertNothingStored();
    }

    public function test_the_hostname_must_be_in_a_configured_allowlist(): void
    {
        config()->set('services.turnstile.allowed_hostnames', ['q3.life', 'www.q3.life']);

        Http::fake([TurnstileVerifier::VERIFY_URL => Http::sequence()
            ->push(['success' => true, 'hostname' => 'evil.example'])
            ->push(['success' => true, 'hostname' => 'www.q3.life']),
        ]);

        $this->postJson(self::URL, $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('errors.turnstile_token', [self::TURNSTILE_ERROR]);
        $this->assertNothingStored();

        $this->postJson(self::URL, $this->payload())->assertCreated();
        $this->assertDatabaseCount('support_tickets', 1);
    }

    public function test_an_unreachable_cloudflare_is_a_503_and_stores_nothing(): void
    {
        Log::spy();
        Http::fake([TurnstileVerifier::VERIFY_URL => Http::failedConnection('Connection timed out')]);

        $this->fromSite()->postJson(self::URL, $this->payload())
            ->assertStatus(503)
            ->assertExactJson(self::UNAVAILABLE_BODY);

        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'Turnstile'))->once();
        $this->assertNothingStored();
    }

    public function test_an_error_response_from_cloudflare_is_a_503(): void
    {
        $this->fakeTurnstile(['error' => 'internal'], 500);

        $this->postJson(self::URL, $this->payload())
            ->assertStatus(503)
            ->assertExactJson(self::UNAVAILABLE_BODY);

        $this->assertNothingStored();
    }

    public function test_it_fails_closed_when_no_secret_is_configured(): void
    {
        config()->set('services.turnstile.secret_key', null);
        Log::spy();
        $this->fakeTurnstile();

        $this->fromSite()->postJson(self::URL, $this->payload())
            ->assertStatus(503)
            ->assertExactJson(self::UNAVAILABLE_BODY);

        Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'Turnstile'))->once();
        Http::assertNothingSent();
        $this->assertNothingStored();
    }

    public function test_it_is_throttled_per_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson(self::URL, $this->payload(['hp_field' => 'bot']))->assertCreated();
        }

        $this->postJson(self::URL, $this->payload())->assertStatus(429);
    }

    // ── Back office ───────────────────────────────────────────────────────────

    public function test_admin_index_renders_a_website_ticket(): void
    {
        $ticket = $this->websiteTicket();

        $this->actingAs($this->admin())
            ->get(route('admin.support.index', ['status' => 'all']))
            ->assertOk()
            ->assertSee($ticket->ticket_number)
            ->assertSee('Wendy Website')
            ->assertSee('wendy@example.test')
            ->assertSee('Website');
    }

    public function test_admin_show_renders_a_website_ticket_with_the_email_reply_notice(): void
    {
        $ticket = $this->websiteTicket();

        $this->actingAs($this->admin())
            ->get(route('admin.support.show', $ticket))
            ->assertOk()
            ->assertSee('Wendy Website')
            ->assertSee('Please delete my data.')
            ->assertSee('does not receive replies posted here')
            ->assertSee('mailto:wendy@example.test?subject=', false)
            ->assertSee(rawurlencode("[{$ticket->ticket_number}]"), false)
            ->assertDontSee('View Profile');
    }

    public function test_admin_show_hints_at_an_unverified_member_email_match(): void
    {
        $member = User::factory()->create(['name' => 'Mona Member', 'email' => 'Wendy@Example.test']);
        $ticket = $this->websiteTicket();

        $this->actingAs($this->admin())
            ->get(route('admin.support.show', $ticket))
            ->assertOk()
            ->assertSee('Mona Member')
            ->assertSee('not verified')
            ->assertSee(route('admin.users.show', $member), false);

        // Hint only — the ticket stays unlinked.
        $this->assertNull($ticket->fresh()->user_id);
    }

    public function test_admin_show_still_renders_a_member_ticket_as_before(): void
    {
        $member = User::factory()->create(['name' => 'Mona Member']);
        $ticket = SupportTicket::create([
            'user_id' => $member->id, 'subject' => 'Member question', 'category' => 'general',
        ]);
        SupportTicketReply::create(['ticket_id' => $ticket->id, 'user_id' => $member->id, 'body' => 'Help me.']);

        $this->assertSame('member', $ticket->fresh()->channel);

        $this->actingAs($this->admin())
            ->get(route('admin.support.show', $ticket))
            ->assertOk()
            ->assertSee('Mona Member')
            ->assertSee('View Profile')
            ->assertDontSee('does not receive replies posted here');
    }

    // ── Member side ───────────────────────────────────────────────────────────

    public function test_a_members_support_index_is_unaffected_by_website_tickets(): void
    {
        $member = User::factory()->create(['email' => 'wendy@example.test', 'is_active' => true]);

        $this->actingAs($member)->post(route('member.support.store'), [
            'subject' => 'My own member ticket',
            'category' => 'general',
            'priority' => 'normal',
            'body' => 'Member body',
        ])->assertRedirect();

        $own = SupportTicket::where('user_id', $member->id)->sole();
        $this->assertSame('member', $own->channel);

        // Same email as the member, but a website ticket is not theirs.
        $website = $this->websiteTicket();

        $this->actingAs($member)
            ->get(route('member.support.index'))
            ->assertOk()
            ->assertSee('My own member ticket')
            ->assertDontSee('Question from the website');

        $this->actingAs($member)
            ->get(route('member.support.show', $website))
            ->assertForbidden();
    }
}
