<?php

namespace App\Mail;

use App\Models\BusinessAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To the account's owner: someone at the client did something on their microsite that needs us. */
class MicrositeActivity extends Mailable
{
    use Queueable, SerializesModels;

    /** @param  list<string>  $lines */
    public function __construct(public readonly BusinessAccount $account, public readonly string $headline, public readonly array $lines) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->headline);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.microsite.activity', with: [
            'headline' => $this->headline,
            'lines' => $this->lines,
            'url' => route('admin.proposal-studio.accounts.site', $this->account),
        ]);
    }
}
