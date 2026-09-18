<?php

namespace App\Mail;

use App\Models\VendorLead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "You have a new order to ship", to the vendor's order desk.
 *
 * The vendor is merchant of record and fulfils, so this carries what they need
 * to ship and match the payment on their Stripe: product, quantity, customer,
 * delivery address and the amounts charged. Nothing about who is paid on our
 * side (partner, commission, our share) — that is ours, not theirs.
 */
class VendorOrderPlaced extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly VendorLead $lead)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $quantity = max(1, (int) $this->lead->quantity);

        return new Envelope(
            subject: "New order {$this->lead->public_ref}: {$quantity} × {$this->lead->productName()}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.vendor-order-placed',
            with: [
                'lead'  => $this->lead,
                'money' => fn (?int $minor): string => $minor === null
                    ? '—'
                    : '$'.number_format($minor / 100, 2).($this->lead->currency && $this->lead->currency !== 'USD' ? ' '.$this->lead->currency : ''),
            ],
        );
    }
}
