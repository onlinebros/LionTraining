<?php

namespace App\Mail;

use App\Models\PortalSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The answer to a question a prospect asked in their portal. */
class PortalAnswered extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly PortalSubmission $submission) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'An answer to your question');
    }

    public function content(): Content
    {
        $prospect = $this->submission->prospect;

        return new Content(markdown: 'mail.portal.answered', with: [
            'submission' => $this->submission,
            'prospect' => $prospect,
            'url' => route('portal.show', $prospect->portal_ref),
        ]);
    }
}
