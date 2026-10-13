<?php

namespace App\Mail;

use App\Models\VendorLead;
use App\Services\Vendor\PaymentGuidance;
use App\Support\Vendors;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your order is not complete yet", to the BUYER.
 *
 * Sent when someone comes back from checkout with a payment that has not been
 * received — most often a financing application (Affirm, Klarna) left part-way.
 * Buyers in that position used to see "your order is placed" and believe it.
 * This says the opposite in the subject line, and tells them exactly what to
 * look for to finish, so it can never be mistaken for a confirmation.
 *
 * The state is passed in rather than re-read: it is what the buyer was shown,
 * and the email must match the page.
 */
class VendorPaymentIncomplete extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly VendorLead $lead,
        public readonly string $state,
        public readonly ?string $method = null,
        public readonly bool $abandoned = false,
        public readonly ?string $nextAction = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->guidance()->subject);
    }

    public function content(): Content
    {
        $vendor = Vendors::find((string) $this->lead->vendor) ?? [];

        return new Content(
            markdown: 'mail.vendor-payment-incomplete',
            with: [
                'lead'     => $this->lead,
                'guide'    => $this->guidance(),
                'orderUrl' => route('vendor.order', $this->lead->public_ref),
                'seller'   => $vendor['name'] ?? 'The seller',
                'support'  => $vendor['support_email'] ?? '',
            ],
        );
    }

    private function guidance(): PaymentGuidance
    {
        return PaymentGuidance::forState(
            $this->state, $this->method, $this->abandoned, $this->nextAction,
            $this->lead, Vendors::find((string) $this->lead->vendor) ?? [],
        );
    }
}
