<?php

namespace App\Mail;

use App\Models\MicrositeMember;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The sign-in link to a business account's microsite. Sent at once rather
 * than queued: the token exists only in this message.
 */
class MicrositeInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly MicrositeMember $member, public readonly string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Prepared for '.$this->member->account->name.' by Quantum 3 Solution');
    }

    public function content(): Content
    {
        $account = $this->member->account;

        return new Content(markdown: 'mail.microsite.invitation', with: [
            'member' => $this->member,
            'account' => $account,
            'requestedBy' => $this->member->requestedBy?->label(),
            'url' => route('microsite.enter', [$account->site_ref, $this->token]),
            'days' => MicrositeMember::LINK_DAYS,
        ]);
    }
}
