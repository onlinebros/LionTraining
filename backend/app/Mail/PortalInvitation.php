<?php

namespace App\Mail;

use App\Models\PortalInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The sign-in link to a prospect's portal. Sent at once rather than queued:
 * the token exists only in this message.
 */
class PortalInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly PortalInvite $invite, public readonly string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your proposal from Quantum 3 Solution: '.$this->invite->prospect->company_name);
    }

    public function content(): Content
    {
        $prospect = $this->invite->prospect;

        return new Content(markdown: 'mail.portal.invitation', with: [
            'invite' => $this->invite,
            'prospect' => $prospect,
            'offering' => $prospect->offering,
            'url' => route('portal.enter', [$prospect->portal_ref, $this->token]),
            'days' => PortalInvite::LINK_DAYS,
        ]);
    }
}
