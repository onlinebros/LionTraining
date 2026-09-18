<?php

namespace Tests\Feature\Vendor;

use App\Mail\VendorOrderPlaced;
use App\Models\User;
use App\Models\VendorLead;
use App\Services\Vendor\VendorReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * PlasmaGuard's order desk hears about every sale, once, and only real ones.
 */
class VendorOrderEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        config()->set('vendors.vendors.plasmaguard.enabled', true);
        config()->set('vendors.vendors.plasmaguard.stripe.mode', 'live');
        config()->set('vendors.vendors.plasmaguard.order_email', 'orders@plasmaguard.com');

        User::factory()->create(['referral_code' => 'PARTNER1', 'is_active' => true]);
    }

    public function test_a_confirmed_sale_emails_the_order_desk_with_the_details(): void
    {
        $lead = $this->lead();

        $this->confirm($lead);

        Mail::assertQueued(VendorOrderPlaced::class, function (VendorOrderPlaced $mail) use ($lead) {
            $html = $mail->render();

            return $mail->hasTo('orders@plasmaguard.com')
                && $mail->lead->is($lead)
                && str_contains($html, $lead->public_ref)
                && str_contains($html, 'PlasmaGuard PRO In-Duct System')
                && str_contains($html, 'Casey Customer')
                && str_contains($html, 'casey@example.com')
                && str_contains($html, '48 Maple Avenue')
                && str_contains($html, 'Ann Arbor, MI 48104')
                && str_contains($html, '$6,414.00');
        });
    }

    public function test_it_says_nothing_about_who_we_pay(): void
    {
        $lead = $this->lead();

        $this->confirm($lead);

        Mail::assertQueued(VendorOrderPlaced::class, function (VendorOrderPlaced $mail) {
            $html = $mail->render();

            return ! str_contains($html, 'PARTNER1') && ! str_contains(strtolower($html), 'commission');
        });
    }

    public function test_a_redelivered_confirmation_does_not_email_twice(): void
    {
        $lead = $this->lead();

        $this->confirm($lead);
        $this->confirm($lead);

        Mail::assertQueuedCount(1);
    }

    public function test_a_sandbox_sale_is_never_sent_to_the_real_order_desk(): void
    {
        config()->set('vendors.vendors.plasmaguard.stripe.mode', 'test');

        $this->confirm($this->lead());

        Mail::assertNothingQueued();
    }

    public function test_an_empty_address_switches_it_off(): void
    {
        config()->set('vendors.vendors.plasmaguard.order_email', '');

        $this->confirm($this->lead());

        Mail::assertNothingQueued();
    }

    private function lead(): VendorLead
    {
        $this->post('/p/PARTNER1/plasmaguard/pro-in-duct', [
            'first_name' => 'Casey',
            'last_name'  => 'Customer',
            'email'      => 'casey@example.com',
        ])->assertRedirect();

        $lead = VendorLead::latest('id')->firstOrFail();

        $lead->update([
            'address_line1' => '48 Maple Avenue',
            'city'          => 'Ann Arbor',
            'state'         => 'MI',
            'postal_code'   => '48104',
            'country'       => 'US',
            'quantity'      => 1,
        ]);

        return $lead;
    }

    private function confirm(VendorLead $lead): void
    {
        app(VendorReferralService::class)->convert(
            $lead,
            ['amount_total' => 641400, 'currency' => 'USD'],
            VendorLead::VIA_MANUAL,
        );
    }
}
