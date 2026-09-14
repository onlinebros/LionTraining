<?php

namespace Tests\Feature\Vendor;

use App\Models\Role;
use App\Models\User;
use App\Models\VendorLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Collecting our share.
 *
 * Nothing is taken at the point of sale under the direct-key arrangement, so
 * these totals are the only place the revenue share is visible. If they drift,
 * money quietly stops being chased.
 */
class VendorReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => Role::SUPER_ADMIN],
            ['display_name' => 'Super Admin', 'is_admin' => true, 'level' => 100]);
        $this->admin = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    public function test_it_totals_only_what_is_owed_and_not_yet_billed(): void
    {
        $this->order(300000);                                  // owed
        $this->order(600000);                                  // owed
        $this->order(300000, invoiced: 'INV-1');               // billed, awaiting payment
        $this->order(300000, invoiced: 'INV-0', settled: true); // done
        $this->order(300000, status: VendorLead::STATUS_NEW);   // never sold

        $response = $this->actingAs($this->admin)->get(route('admin.vendor-leads.reconciliation'));

        $response->assertOk();
        $totals = $response->viewData('totals');

        $this->assertSame(900000, $totals['owed']);      // the two uninvoiced only
        $this->assertSame(2,      $totals['owed_n']);
        $this->assertSame(300000, $totals['invoiced']);
        $this->assertSame(300000, $totals['settled']);
    }

    public function test_invoicing_a_batch_stamps_the_reference(): void
    {
        $a = $this->order(300000);
        $b = $this->order(300000);

        $this->actingAs($this->admin)->post(route('admin.vendor-leads.invoice'), [
            'reference' => 'Q3-PG-2026-09',
            'ids'       => [$a->id, $b->id],
        ])->assertRedirect();

        $this->assertSame('Q3-PG-2026-09', $a->refresh()->invoice_reference);
        $this->assertNotNull($b->refresh()->invoiced_at);
    }

    public function test_invoicing_never_rewrites_an_order_already_billed(): void
    {
        // A stale tab or a double submit must not move an order onto a second
        // invoice — that is how the same sale gets billed twice.
        $order = $this->order(300000, invoiced: 'INV-ORIGINAL');

        $this->actingAs($this->admin)->post(route('admin.vendor-leads.invoice'), [
            'reference' => 'INV-SECOND',
            'ids'       => [$order->id],
        ]);

        $this->assertSame('INV-ORIGINAL', $order->refresh()->invoice_reference);
    }

    public function test_an_invoice_is_settled_as_a_whole(): void
    {
        // The vendor pays an invoice, not a line.
        $a = $this->order(300000, invoiced: 'INV-7');
        $b = $this->order(600000, invoiced: 'INV-7');
        $c = $this->order(300000, invoiced: 'INV-8');

        $this->actingAs($this->admin)->post(route('admin.vendor-leads.settle'), ['reference' => 'INV-7']);

        $this->assertNotNull($a->refresh()->settled_at);
        $this->assertNotNull($b->refresh()->settled_at);
        $this->assertNull($c->refresh()->settled_at);
    }

    public function test_tracking_can_be_recorded_against_an_order(): void
    {
        $order = $this->order(300000);

        $this->actingAs($this->admin)->post(route('admin.vendor-leads.fulfil', $order->id), [
            'carrier'         => 'FedEx',
            'tracking_number' => '794657123456',
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('794657123456', $order->tracking_number);
        $this->assertNotNull($order->shipped_at);
    }

    private function order(int $share, ?string $invoiced = null, bool $settled = false, string $status = VendorLead::STATUS_CONVERTED): VendorLead
    {
        return VendorLead::create([
            'public_ref'       => 'QLV-'.strtoupper(\Illuminate\Support\Str::random(10)),
            'vendor'           => 'plasmaguard',
            'product_key'      => 'pro-in-duct',
            'first_name'       => 'Dana',
            'email'            => 'dana@example.com',
            'quantity'         => 1,
            'status'           => $status,
            'converted_at'     => $status === VendorLead::STATUS_CONVERTED ? now() : null,
            'our_share_amount' => $share,
            'amount_total'     => 641400,
            'currency'         => 'USD',
            'invoiced_at'       => $invoiced ? now() : null,
            'invoice_reference' => $invoiced,
            'settled_at'        => $settled ? now() : null,
        ]);
    }
}
