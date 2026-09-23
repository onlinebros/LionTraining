<?php

namespace Tests\Feature\ProductPartner;

use App\Models\ProductPartnerAssignment;
use App\Models\ProductPartnerPayment;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The account between the vendor and Quantum 3.
 *
 * The property being pinned throughout: the vendor can put their side of it on
 * the record, and cannot move the balance. A debtor who can mark their own debt
 * settled produces a number only one side believes, which is the exact problem
 * this screen was built to end.
 */
class ProductPartnerStatementTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => Role::SUPER_ADMIN],
            ['display_name' => 'Super Admin', 'is_admin' => true, 'level' => 99]);

        $this->admin = User::factory()->create([
            'role_id' => Role::findByName(Role::SUPER_ADMIN)->id, 'is_active' => true,
        ]);

        $this->partner = User::factory()->create([
            'role_id' => Role::findByName(Role::PRODUCT_PARTNER)->id, 'is_active' => true,
        ]);

        ProductPartnerAssignment::create([
            'user_id'     => $this->partner->id,
            'vendor'      => 'plasmaguard',
            'product_key' => ProductPartnerAssignment::ALL_PRODUCTS,
        ]);
    }

    public function test_the_statement_totals_what_is_owed_and_what_has_been_paid(): void
    {
        $this->order(300000);                                    // earned, not invoiced
        $this->order(600000, invoiced: 'INV-1');                 // invoiced, unpaid
        $this->order(300000, invoiced: 'INV-0', settled: true);  // done
        $this->order(300000, status: VendorLead::STATUS_NEW);     // never sold

        $totals = $this->actingAs($this->partner)
            ->get(route('product-partner.statement'))
            ->assertOk()
            ->viewData('totals');

        $this->assertSame(1200000, $totals['earned']);
        $this->assertSame(300000,  $totals['uninvoiced']);
        $this->assertSame(900000,  $totals['invoiced']);
        $this->assertSame(300000,  $totals['settled']);

        // Nothing confirmed yet, so the whole debt is outstanding.
        $this->assertSame(0,       $totals['paid']);
        $this->assertSame(1200000, $totals['outstanding']);
    }

    public function test_an_order_refunded_after_invoicing_comes_off_the_balance(): void
    {
        $this->order(600000, invoiced: 'INV-1');
        $this->order(300000, invoiced: 'INV-1', status: VendorLead::STATUS_REFUNDED);

        $totals = $this->actingAs($this->partner)
            ->get(route('product-partner.statement'))->viewData('totals');

        // The refunded order stops being earned AND is credited back, so the
        // balance is the one good order rather than the pair.
        $this->assertSame(600000, $totals['earned']);
        $this->assertSame(300000, $totals['credits']);
        $this->assertSame(300000, $totals['outstanding']);
    }

    public function test_a_partner_can_record_a_payment_and_it_starts_pending(): void
    {
        $this->order(600000, invoiced: 'INV-1');

        $this->actingAs($this->partner)
            ->post(route('product-partner.statement.payments'), [
                'amount'            => '6000.00',
                'paid_on'           => now()->toDateString(),
                'method'            => 'wire',
                'reference'         => 'WIRE-88231',
                'invoice_reference' => 'INV-1',
            ])->assertRedirect();

        $payment = ProductPartnerPayment::first();

        $this->assertNotNull($payment);
        $this->assertSame(600000, (int) $payment->amount);   // stored in minor units
        $this->assertSame(ProductPartnerPayment::STATUS_PENDING, $payment->status);
        $this->assertSame($this->partner->id, $payment->recorded_by_user_id);
    }

    public function test_a_pending_payment_does_not_move_the_balance(): void
    {
        $this->order(600000, invoiced: 'INV-1');

        $this->actingAs($this->partner)->post(route('product-partner.statement.payments'), [
            'amount'  => '6000.00',
            'paid_on' => now()->toDateString(),
        ]);

        $totals = $this->actingAs($this->partner)
            ->get(route('product-partner.statement'))->viewData('totals');

        $this->assertSame(600000, $totals['pending']);
        $this->assertSame(0,      $totals['paid']);
        $this->assertSame(600000, $totals['outstanding'], 'A claim is not a payment.');
    }

    public function test_a_partner_cannot_settle_an_invoice_themselves(): void
    {
        $order = $this->order(600000, invoiced: 'INV-1');

        // There is no route for it, and the admin one is closed to them.
        $this->actingAs($this->partner)
            ->post(route('admin.vendor-leads.settle'), ['reference' => 'INV-1'])
            ->assertRedirect(route('product-partner.dashboard'));

        $this->assertNull($order->refresh()->settled_at);
    }

    public function test_confirming_a_payment_settles_the_invoice_it_names(): void
    {
        $a = $this->order(300000, invoiced: 'INV-7');
        $b = $this->order(300000, invoiced: 'INV-7');
        $c = $this->order(300000, invoiced: 'INV-8');

        $this->actingAs($this->partner)->post(route('product-partner.statement.payments'), [
            'amount'            => '6000.00',
            'paid_on'           => now()->toDateString(),
            'invoice_reference' => 'INV-7',
        ]);

        $payment = ProductPartnerPayment::first();

        $this->actingAs($this->admin)
            ->post(route('admin.product-partners.payments.confirm', $payment))
            ->assertRedirect();

        // Both halves happen together — see PartnerStatement::confirmPayment().
        $this->assertTrue($payment->refresh()->isConfirmed());
        $this->assertNotNull($a->refresh()->settled_at);
        $this->assertNotNull($b->refresh()->settled_at);
        $this->assertNull($c->refresh()->settled_at, 'Only the invoice named should be settled.');
    }

    public function test_a_confirmed_payment_comes_off_the_outstanding_balance(): void
    {
        $this->order(600000, invoiced: 'INV-1');

        $this->actingAs($this->partner)->post(route('product-partner.statement.payments'), [
            'amount'  => '2000.00',
            'paid_on' => now()->toDateString(),
        ]);

        $this->actingAs($this->admin)->post(
            route('admin.product-partners.payments.confirm', ProductPartnerPayment::first())
        );

        $totals = $this->actingAs($this->partner)
            ->get(route('product-partner.statement'))->viewData('totals');

        $this->assertSame(200000, $totals['paid']);
        $this->assertSame(400000, $totals['outstanding']);
    }

    public function test_a_payment_against_an_invoice_that_is_not_open_is_refused(): void
    {
        // Filing against an invoice that does not exist reconciles to nothing
        // and surfaces weeks later as an argument.
        $this->order(600000, invoiced: 'INV-1');

        $this->actingAs($this->partner)
            ->post(route('product-partner.statement.payments'), [
                'amount'            => '6000.00',
                'paid_on'           => now()->toDateString(),
                'invoice_reference' => 'INV-DOES-NOT-EXIST',
            ])
            ->assertSessionHasErrors('invoice_reference');

        $this->assertSame(0, ProductPartnerPayment::count());
    }

    public function test_a_future_dated_payment_is_refused(): void
    {
        $this->actingAs($this->partner)
            ->post(route('product-partner.statement.payments'), [
                'amount'  => '100.00',
                'paid_on' => now()->addWeek()->toDateString(),
            ])
            ->assertSessionHasErrors('paid_on');
    }

    public function test_a_payment_cannot_be_decided_twice(): void
    {
        $payment = ProductPartnerPayment::create([
            'vendor' => 'plasmaguard', 'amount' => 100000, 'paid_on' => now()->toDateString(),
        ]);

        $this->actingAs($this->admin)->post(route('admin.product-partners.payments.confirm', $payment));

        $this->actingAs($this->admin)
            ->post(route('admin.product-partners.payments.reject', $payment), ['decision_note' => 'Changed my mind'])
            ->assertSessionHasErrors('error');

        $this->assertTrue($payment->refresh()->isConfirmed());
    }

    public function test_a_rejection_tells_the_partner_why(): void
    {
        $payment = ProductPartnerPayment::create([
            'vendor' => 'plasmaguard', 'amount' => 100000, 'paid_on' => now()->toDateString(),
        ]);

        $this->actingAs($this->admin)->post(
            route('admin.product-partners.payments.reject', $payment),
            ['decision_note' => 'No matching deposit on that date.'],
        );

        $this->actingAs($this->partner)
            ->get(route('product-partner.statement'))
            ->assertOk()
            ->assertSee('No matching deposit on that date.');
    }

    public function test_a_partner_only_sees_their_own_vendors_payments(): void
    {
        ProductPartnerPayment::create([
            'vendor' => 'otherco', 'amount' => 999900, 'paid_on' => now()->toDateString(),
            'reference' => 'NOT-YOURS',
        ]);

        $this->actingAs($this->partner)
            ->get(route('product-partner.statement'))
            ->assertOk()
            ->assertDontSee('NOT-YOURS');
    }

    private function order(
        int $share,
        ?string $invoiced = null,
        bool $settled = false,
        string $status = VendorLead::STATUS_CONVERTED,
    ): VendorLead {
        return VendorLead::create([
            'public_ref'        => 'QLV-'.strtoupper(Str::random(10)),
            'vendor'            => 'plasmaguard',
            'product_key'       => 'pro-in-duct',
            'first_name'        => 'Dana',
            'email'             => 'dana@example.com',
            'quantity'          => 1,
            'status'            => $status,
            'converted_at'      => $status === VendorLead::STATUS_NEW ? null : now(),
            'refunded_at'       => $status === VendorLead::STATUS_REFUNDED ? now() : null,
            'our_share_amount'  => $share,
            'amount_total'      => 641400,
            'currency'          => 'USD',
            'invoiced_at'       => $invoiced ? now() : null,
            'invoice_reference' => $invoiced,
            'settled_at'        => $settled ? now() : null,
        ]);
    }
}
