<?php

namespace Tests\Feature\Partner;

use App\Jobs\DeliverPartnerWebhookJob;
use App\Models\PartnerCompany;
use App\Models\PartnerWebhookDelivery;
use App\Models\Role;
use App\Models\User;
use App\Services\Partner\ActivationCode;
use App\Services\Genealogy\EnrollmentService;
use App\Services\Partner\PartnerWebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Telling a partner company that one of their positions has an owner now.
 *
 * Two things these tests exist to hold. First, a partner's endpoint can never
 * break a claim — the member is standing in front of the form. Second, the
 * member's contact details go out only when somebody deliberately switched that
 * on for the company, because sending them is a disclosure to a third party.
 */
class PartnerWebhookTest extends TestCase
{
    use RefreshDatabase;

    private PartnerCompany $company;
    private User $founder;
    private User $spot;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => Role::FREE_MEMBER, 'display_name' => 'Free Member', 'is_admin' => false, 'level' => 1]);

        $this->company = PartnerCompany::create([
            'slug'            => 'acme',
            'name'            => 'Acme Group',
            'is_active'       => true,
            'webhook_url'     => 'https://api.acme.example/quantum/events',
            'webhook_secret'  => 'a-secret-at-least-16-chars',
            'webhook_enabled' => true,
        ]);

        $this->founder = User::factory()->create();
        app(EnrollmentService::class)->enroll($this->founder, null);
        $this->founder->refresh();

        $this->spot = $this->makeSpot('IHUB-1001', 'CODE-ALPHA', $this->founder);
    }

    private function makeSpot(string $externalId, string $code, User $parent): User
    {
        $spot = new User(['name' => "Spot {$externalId}"]);
        $spot->account_status       = User::ACCOUNT_HOLDING;
        $spot->partner_company_id   = $this->company->id;
        $spot->external_user_id     = $externalId;
        $spot->activation_code_hash = ActivationCode::hash($code);
        $spot->imported_at          = now();
        $spot->is_active            = false;
        $spot->save();

        return app(EnrollmentService::class)->enrollImported($spot, $parent)->refresh();
    }

    /** Walk a spot through the public claim flow. */
    private function claim(string $externalId = 'IHUB-1001', string $code = 'CODE-ALPHA'): void
    {
        $this->post(route('partner.claim.verify', 'acme'), [
            'external_user_id' => $externalId,
            'activation_code'  => $code,
        ]);

        $this->post(route('partner.claim.store', 'acme'), [
            'name'                  => 'Dana Whitfield',
            'email'                 => 'dana@example.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'phone'                 => '555-0142',
            'terms'                 => '1',
        ]);
    }

    // ── Firing ────────────────────────────────────────────────────────────────

    public function test_claiming_a_spot_records_and_queues_an_event(): void
    {
        Http::fake();

        $this->claim();

        $delivery = PartnerWebhookDelivery::firstOrFail();

        $this->assertSame(PartnerWebhookDelivery::EVENT_SPOT_CLAIMED, $delivery->event_type);
        $this->assertSame('IHUB-1001', $delivery->external_user_id);
        $this->assertSame($this->spot->id, $delivery->user_id);
    }

    public function test_the_event_describes_the_claim_and_the_position(): void
    {
        Http::fake();

        $this->claim();

        $payload = PartnerWebhookDelivery::firstOrFail()->payload;

        $this->assertSame('IHUB-1001', $payload['external_user_id']);
        $this->assertNotNull($payload['claimed_at']);
        $this->assertSame($this->spot->id, $payload['quantum']['user_id']);

        // The leg hangs beneath a Quantum partner, not another imported spot,
        // so there is no external parent to report — and saying so explicitly
        // is more useful to the partner than omitting the key.
        $this->assertNull($payload['position']['external_parent_id']);
        $this->assertFalse($payload['position']['parent_is_partner_spot']);
    }

    public function test_the_event_names_their_own_id_for_the_position_above(): void
    {
        $below = $this->makeSpot('IHUB-1002', 'CODE-BETA', $this->spot);

        Http::fake();
        $this->claim('IHUB-1002', 'CODE-BETA');

        $payload = PartnerWebhookDelivery::where('user_id', $below->id)->firstOrFail()->payload;

        // Their identifier, because that is the key they can join on — our user
        // id means nothing in their system.
        $this->assertSame('IHUB-1001', $payload['position']['external_parent_id']);
        $this->assertTrue($payload['position']['parent_is_partner_spot']);
    }

    public function test_a_company_with_no_webhook_gets_nothing(): void
    {
        $this->company->update(['webhook_enabled' => false]);

        Http::fake();
        $this->claim();

        $this->assertSame(0, PartnerWebhookDelivery::count());
        // And the claim still went through.
        $this->assertTrue($this->spot->refresh()->isActivated());
    }

    public function test_a_failing_endpoint_does_not_fail_the_claim(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        $this->claim();

        // The member is standing in front of the form; somebody else's server
        // being down is not their problem.
        $this->assertTrue($this->spot->refresh()->isActivated());
        $this->assertNotSame(
            PartnerWebhookDelivery::STATUS_DELIVERED,
            PartnerWebhookDelivery::firstOrFail()->status,
        );
    }

    // ── Contact details ───────────────────────────────────────────────────────

    public function test_contact_details_are_withheld_by_default(): void
    {
        Http::fake();

        $this->claim();

        $payload = PartnerWebhookDelivery::firstOrFail()->payload;

        // Sending these is a disclosure to a third party, so it is a switch
        // somebody throws, not a default.
        $this->assertArrayNotHasKey('member', $payload);
        $this->assertStringNotContainsString('dana@example.com', json_encode($payload));
        $this->assertStringNotContainsString('555-0142', json_encode($payload));
    }

    public function test_contact_details_are_sent_when_the_company_is_set_to_receive_them(): void
    {
        $this->company->update(['webhook_include_contact' => true]);

        Http::fake();
        $this->claim();

        $payload = PartnerWebhookDelivery::firstOrFail()->payload;

        $this->assertSame('Dana Whitfield', $payload['member']['name']);
        $this->assertSame('dana@example.com', $payload['member']['email']);
        $this->assertSame('555-0142', $payload['member']['phone']);
    }

    // ── Delivery ──────────────────────────────────────────────────────────────

    public function test_a_delivery_is_signed_so_the_partner_can_verify_it(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $this->claim();

        $delivery = PartnerWebhookDelivery::firstOrFail();
        $body     = app(PartnerWebhookDispatcher::class)->encode($delivery);

        Http::assertSent(function ($request) use ($body, $delivery) {
            $header = $request->header('Q3-Signature')[0] ?? '';

            preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $header, $m);
            $this->assertNotEmpty($m, "Signature header was not in the expected form: {$header}");

            // Recomputed exactly as the partner's own code would.
            $expected = hash_hmac('sha256', "{$m[1]}.{$body}", 'a-secret-at-least-16-chars');

            return hash_equals($expected, $m[2])
                && $request->header('Q3-Event-Id')[0] === $delivery->event_id
                && $request->body() === $body;
        });
    }

    public function test_a_partner_gets_the_signature_their_own_receiver_reads(): void
    {
        // iHub's endpoint verifies `X-Partner-Signature: sha256=<hmac of the
        // body>` — GitHub's convention, no timestamp. We do not get to pick
        // this: their receiver already exists.
        $this->company->update([
            'webhook_signature_style' => \App\Models\PartnerCompany::SIGNATURE_SHA256,
        ]);

        Http::fake(['*' => Http::response('ok', 200)]);
        $this->claim();

        $body = app(PartnerWebhookDispatcher::class)
            ->encode(PartnerWebhookDelivery::firstOrFail());

        Http::assertSent(function ($request) use ($body) {
            $expected = 'sha256=' . hash_hmac('sha256', $body, 'a-secret-at-least-16-chars');

            return ($request->header('X-Partner-Signature')[0] ?? '') === $expected
                // And the timestamped header is not also sent — two signatures
                // is an invitation to verify the wrong one.
                && ($request->header('Q3-Signature')[0] ?? null) === null;
        });
    }

    public function test_a_successful_delivery_is_recorded(): void
    {
        Http::fake(['*' => Http::response('thanks', 202)]);

        $this->claim();

        $delivery = PartnerWebhookDelivery::firstOrFail();

        $this->assertSame(PartnerWebhookDelivery::STATUS_DELIVERED, $delivery->status);
        $this->assertSame(202, $delivery->response_status);
        $this->assertNotNull($delivery->delivered_at);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($this->company->refresh()->webhook_last_success_at);
    }

    public function test_a_refused_delivery_keeps_what_the_endpoint_said(): void
    {
        Http::fake(['*' => Http::response('unknown user id', 422)]);

        $this->claim();

        $delivery = PartnerWebhookDelivery::firstOrFail();

        // The whole point of the ledger: when a partner says "we never heard
        // about that claim", this is the answer.
        $this->assertSame(422, $delivery->response_status);
        $this->assertStringContainsString('unknown user id', $delivery->response_body);
        $this->assertSame(1, $this->company->refresh()->webhook_consecutive_failures);
    }

    public function test_a_replay_reuses_the_event_id(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->claim();

        $delivery = PartnerWebhookDelivery::firstOrFail();
        $eventId  = $delivery->event_id;

        app(PartnerWebhookDispatcher::class)->deliver($delivery->fresh());

        // Same id, so a partner keying idempotency on it recognises a replay
        // rather than counting the claim twice.
        $this->assertSame(1, PartnerWebhookDelivery::count());
        $this->assertSame($eventId, $delivery->fresh()->event_id);
    }

    public function test_exhausted_retries_mark_the_event_failed(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);
        $this->claim();

        $delivery = PartnerWebhookDelivery::firstOrFail();

        (new DeliverPartnerWebhookJob($delivery->id))->failed(new \RuntimeException('gave up'));

        $this->assertSame(PartnerWebhookDelivery::STATUS_FAILED, $delivery->fresh()->status);
    }

    /**
     * The guide is sent to somebody outside this company and is the only
     * description of the payload they get. A field renamed here and not there
     * is an integration that breaks on their side with no warning on ours.
     */
    public function test_the_integration_guide_documents_the_fields_we_actually_send(): void
    {
        $this->company->update(['webhook_include_contact' => true]);

        Http::fake();
        $this->claim();

        $guide   = file_get_contents(base_path('resources/templates/partner-webhook-guide.md'));
        $payload = PartnerWebhookDelivery::firstOrFail()->payload;

        foreach ($this->leafPaths($payload) as $path) {
            $this->assertStringContainsString(
                $path,
                $guide,
                "The webhook guide does not mention `data.{$path}`, which we send.",
            );
        }

        // And the headers a partner has to read to verify anything.
        foreach (['Q3-Signature', 'Q3-Event-Id', 'Q3-Event-Type'] as $header) {
            $this->assertStringContainsString($header, $guide);
        }
    }

    /**
     * Dotted paths to every value in a nested payload.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function leafPaths(array $payload, string $prefix = ''): array
    {
        $paths = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $paths = array_merge($paths, $this->leafPaths($value, $path));

                continue;
            }

            $paths[] = $path;
        }

        return $paths;
    }

    public function test_an_already_delivered_event_is_not_sent_again_by_a_retry(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->claim();

        $delivery = PartnerWebhookDelivery::firstOrFail();

        // A queue retry that races a successful attempt must not re-POST.
        (new DeliverPartnerWebhookJob($delivery->id))->handle(app(PartnerWebhookDispatcher::class));

        Http::assertSentCount(1);
    }
}
