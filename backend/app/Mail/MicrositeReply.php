<?php

namespace App\Mail;

use App\Models\MicrositeComment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To a client: we replied to their feedback on their microsite. */
class MicrositeReply extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly MicrositeComment $comment) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'We replied to your feedback: '.$this->comment->section->title);
    }

    public function content(): Content
    {
        $account = $this->comment->section->account;

        return new Content(markdown: 'mail.microsite.reply', with: [
            'comment' => $this->comment,
            'section' => $this->comment->section,
            'url' => route('microsite.show', $account->site_ref).'#s-'.$this->comment->microsite_section_id,
        ]);
    }
}
