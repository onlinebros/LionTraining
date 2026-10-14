<?php

namespace Tests\Feature\OwnerStatus;

use App\Http\Middleware\RequireOwnerStatusToken;
use App\Models\CommissionLedger;
use App\Models\CommissionPayout;
use App\Models\ErrorLog;
use App\Models\Role;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\User;
use App\Models\VendorLead;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The read-only owner-status API the owner's assistant reads. */
class OwnerStatusApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'owner-status-test-token-0123456789';

    private const ENDPOINTS = [
        'summary' => ['generated_at', 'app', 'sales_30d', 'tickets', 'errors_7d', 'approvals'],
        'sales' => ['generated_at', 'days', 'since', 'vendor_sales', 'subscriptions', 'signups'],
        'tickets' => ['generated_at', 'support', 'change_requests'],
        'errors' => ['generated_at', 'days', 'since', 'error_logs', 'failed_jobs', 'stripe_webhooks', 'partner_webhooks',
            'vendor_payment_audits', 'subscriptions_past_due_or_unpaid_count', 'payout_transfers_failed'],
        'approvals' => ['generated_at', 'sponsorships_pending_count', 'commission_ledger_pending', 'commission_payouts',
            'product_partner_payments_pending', 'vendor_leads_attribution_review_count', 'vendor_leads_address_review_count', 'notes'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function issueToken(): void
    {
        SiteSetting::set(RequireOwnerStatusToken::SETTING, hash('sha256', self::TOKEN));
    }

    private function api(): static
    {
        return $this->withToken(self::TOKEN);
    }

    private function user(string $name, string $email): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => 'password']);
        $user->forceFill(['role_id' => Role::findByName(Role::SUPER_ADMIN)->id, 'is_active' => true, 'billing_exempt' => true])->save();

        return $user->refresh();
    }

    public function test_there_is_no_api_without_the_right_token(): void
    {
        foreach (array_keys(self::ENDPOINTS) as $endpoint) {
            $this->withoutToken()->getJson("/api/owner-status/{$endpoint}")->assertNotFound();
            $this->api()->getJson("/api/owner-status/{$endpoint}")->assertNotFound(); // none issued
        }

        $this->issueToken();
        foreach (array_keys(self::ENDPOINTS) as $endpoint) {
            $this->withoutToken()->getJson("/api/owner-status/{$endpoint}")->assertNotFound();
            $this->withToken('wrong')->getJson("/api/owner-status/{$endpoint}")->assertNotFound();
        }
    }

    public function test_the_command_issues_a_working_token_once_and_revokes_it(): void
    {
        Artisan::call('owner-status:token');
        preg_match('/^OWNER_STATUS_TOKEN=(\S{64})$/m', Artisan::output(), $m);
        $this->assertNotEmpty($m, 'the token is printed on its own line');
        $this->assertSame(hash('sha256', $m[1]), SiteSetting::get(RequireOwnerStatusToken::SETTING));

        $this->withToken($m[1])->getJson('/api/owner-status/summary')->assertOk();

        $this->artisan('owner-status:token', ['--revoke' => true])->assertSuccessful();
        $this->withToken($m[1])->getJson('/api/owner-status/summary')->assertNotFound();
    }

    public function test_every_endpoint_answers_on_an_empty_database(): void
    {
        $this->issueToken();

        foreach (self::ENDPOINTS as $endpoint => $keys) {
            $json = $this->api()->getJson("/api/owner-status/{$endpoint}")->assertOk()->assertJsonStructure($keys)->json();
            $this->assertSame($keys, array_keys($json), "{$endpoint} keys");
        }
    }

    public function test_routes_are_get_only(): void
    {
        $this->issueToken();

        foreach (array_keys(self::ENDPOINTS) as $endpoint) {
            $status = $this->api()->postJson("/api/owner-status/{$endpoint}")->status();
            $this->assertContains($status, [404, 405], "POST {$endpoint}");
            $this->assertContains($this->api()->deleteJson("/api/owner-status/{$endpoint}")->status(), [404, 405]);
        }
    }

    public function test_the_days_window_is_clamped(): void
    {
        $this->issueToken();

        $this->api()->getJson('/api/owner-status/sales')->assertJsonPath('days', 30);
        $this->api()->getJson('/api/owner-status/sales?days=0')->assertJsonPath('days', 1);
        $this->api()->getJson('/api/owner-status/sales?days=9999')->assertJsonPath('days', 365);
        $this->api()->getJson('/api/owner-status/sales?days=abc')->assertJsonPath('days', 30);
        $this->api()->getJson('/api/owner-status/errors')->assertJsonPath('days', 7);
        $this->api()->getJson('/api/owner-status/errors?days=-5')->assertJsonPath('days', 1);
        $this->api()->getJson('/api/owner-status/errors?days=500')->assertJsonPath('days', 90);
    }

    public function test_money_is_cents_and_vendor_gross_is_kept_apart_from_our_share(): void
    {
        $this->issueToken();
        $member = $this->user('Pat Partner', 'pat.partner@example.com');

        $lead = VendorLead::create([
            'public_ref' => 'QLV-'.strtoupper(Str::random(10)),
            'vendor' => 'plasmaguard',
            'product_key' => 'pro-in-duct',
            'member_id' => $member->id,
            'first_name' => 'Dana',
            'email' => 'dana.buyer@example.com',
            'phone' => '555-0100',
            'quantity' => 2,
            'status' => VendorLead::STATUS_CONVERTED,
            'converted_at' => now()->subDays(2),
            'amount_total' => 123456,
            'our_share_amount' => 20050,
            'currency' => 'usd',
        ]);
        $lead->forceFill(['credited_member_id' => $member->id])->save();

        CommissionLedger::create(['earner_id' => $member->id, 'type' => 'credit', 'amount' => '12.3400', 'status' => 'pending']);
        CommissionPayout::create(['earner_id' => $member->id, 'total_amount' => '45.6700', 'status' => 'approved']);
        Subscription::forceCreate([
            'user_id' => $member->id, 'provider_subscription_id' => 'sub_test_1', 'status' => Subscription::STATUS_ACTIVE,
            'amount' => 4999, 'currency' => 'usd',
        ]);

        $sales = $this->api()->getJson('/api/owner-status/sales')->assertOk()->json();
        $vendor = $sales['vendor_sales']['vendors'][0];
        $this->assertSame('plasmaguard', $vendor['vendor']);
        $this->assertSame('USD', $vendor['currency']);
        $this->assertSame(1, $vendor['converted_count']);
        $this->assertSame(123456, $vendor['vendor_gross_cents']);
        $this->assertSame(20050, $vendor['our_share_cents']);
        $this->assertSame(20050, $vendor['our_share_uninvoiced_cents']);
        $this->assertSame(123456, $sales['vendor_sales']['sales'][0]['amount_total_cents']);
        $this->assertSame('Pat Partner', $sales['vendor_sales']['sales'][0]['member']);
        $this->assertSame(4999, $sales['subscriptions']['active_amount_by_currency'][0]['active_amount_cents']);

        $approvals = $this->api()->getJson('/api/owner-status/approvals')->assertOk();
        $approvals->assertJsonPath('commission_ledger_pending.count', 1);
        $approvals->assertJsonPath('commission_ledger_pending.credits_cents', 1234);
        $approvals->assertJsonPath('commission_payouts.approved_unpaid.amount_cents', 4567);

        $this->api()->getJson('/api/owner-status/summary')->assertOk()
            ->assertJsonPath('sales_30d.vendor_our_share_cents', 20050)
            ->assertJsonPath('approvals.commission_payouts_approved_unpaid_count', 1);

        $body = $this->api()->getJson('/api/owner-status/sales')->getContent();
        $this->assertStringNotContainsString('@', $body);
        $this->assertStringNotContainsString('555-0100', $body);
        $this->assertStringNotContainsString('sub_test_1', $body);
    }

    public function test_errors_are_grouped_and_stripped_of_private_detail(): void
    {
        $this->issueToken();

        foreach ([1, 2] as $i) {
            ErrorLog::create([
                'level' => 'error', 'message' => 'Duplicate key for someone@example.com', 'file' => base_path('app/Foo.php'), 'line' => 12,
                'url' => 'https://app.q3.life/admin/users?token=secret', 'trace' => 'STACK-TRACE', 'context' => ['secret' => 'CONTEXT'],
            ]);
        }
        ErrorLog::create(['level' => 'error', 'message' => 'Resolved one', 'status' => 'resolved']);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\SendThing', 'data' => 'PAYLOAD-BODY']),
            'exception' => "RuntimeException: boom\n#0 STACK-LINE", 'failed_at' => now(),
        ]);

        $response = $this->api()->getJson('/api/owner-status/errors')->assertOk()
            ->assertJsonPath('error_logs.count', 2)
            ->assertJsonPath('error_logs.top.0.count', 2)
            ->assertJsonPath('error_logs.top.0.location', 'app/Foo.php:12')
            ->assertJsonPath('error_logs.top.0.path', '/admin/users')
            ->assertJsonPath('failed_jobs.count', 1)
            ->assertJsonPath('failed_jobs.recent.0.job', 'App\\Jobs\\SendThing')
            ->assertJsonPath('failed_jobs.recent.0.exception', 'RuntimeException: boom');

        $body = $response->getContent();
        foreach (['@', 'secret', 'STACK', 'CONTEXT', 'PAYLOAD-BODY'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }

    public function test_tickets_show_names_but_never_email_addresses(): void
    {
        $this->issueToken();
        $staff = $this->user('Sam Staff', 'sam.staff@example.com');
        $member = $this->user('Mia Member', 'mia.member@example.com');

        $ticket = SupportTicket::create([
            'user_id' => $member->id, 'subject' => 'Cannot log in, write me at mia.member@example.com',
            'category' => 'account', 'priority' => 'high', 'status' => 'open', 'assigned_to' => $staff->id,
        ]);
        SupportTicketReply::create(['ticket_id' => $ticket->id, 'user_id' => $member->id, 'body' => 'Still stuck, mia.member@example.com']);
        SupportTicket::create([
            'user_id' => null, 'channel' => SupportTicket::CHANNEL_WEBSITE, 'requester_name' => 'Wendy Website',
            'requester_email' => 'wendy@example.test', 'subject' => 'Question', 'category' => 'general',
            'priority' => 'normal', 'status' => 'in_progress',
        ]);
        SupportTicket::create(['user_id' => $member->id, 'subject' => 'Old', 'category' => 'general', 'priority' => 'low', 'status' => 'closed']);

        $response = $this->api()->getJson('/api/owner-status/tickets')->assertOk();
        $response->assertJsonPath('support.by_status.open', 1)
            ->assertJsonPath('support.by_status.closed', 1)
            ->assertJsonPath('support.open_by_priority.high', 1)
            ->assertJsonCount(2, 'support.open');

        $open = collect($response->json('support.open'))->keyBy('requester');
        $this->assertSame('Sam Staff', $open['Mia Member']['assigned_to']);
        $this->assertNotNull($open['Mia Member']['last_reply_at']);
        $this->assertArrayHasKey('Wendy Website', $open->all());

        $this->assertStringNotContainsString('@', $response->getContent());
    }

    public function test_one_ticket_returns_its_whole_thread_without_email_addresses(): void
    {
        $this->issueToken();
        $staff = $this->user('Sam Staff', 'sam.staff@example.com');
        $member = $this->user('Mia Member', 'mia.member@example.com');
        $ticket = SupportTicket::create([
            'user_id' => $member->id, 'subject' => 'Order not shipped', 'category' => 'orders', 'priority' => 'high', 'status' => 'open',
        ]);
        SupportTicketReply::create(['ticket_id' => $ticket->id, 'user_id' => $member->id, 'body' => '<p>Paid with Affirm, mail me at mia.member@example.com</p>']);
        SupportTicketReply::create(['ticket_id' => $ticket->id, 'user_id' => $staff->id, 'body' => 'Checking Stripe', 'is_internal' => true]);

        $response = $this->api()->getJson("/api/owner-status/tickets/{$ticket->id}")->assertOk();
        $response->assertJsonPath('ticket.subject', 'Order not shipped')
            ->assertJsonPath('ticket.requester_has_account', true)
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.from_requester', true)
            ->assertJsonPath('messages.1.internal_note', true)
            ->assertJsonPath('messages.1.author', 'Sam Staff');
        $this->assertStringStartsWith('Paid with Affirm', $response->json('messages.0.body'));
        $this->assertStringNotContainsString('@', $response->getContent());

        $this->api()->getJson('/api/owner-status/tickets/999999')->assertNotFound();
        $this->api()->postJson("/api/owner-status/tickets/{$ticket->id}")->assertStatus(405);
    }
}
