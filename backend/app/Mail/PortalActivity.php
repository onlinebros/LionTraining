<?php

namespace App\Mail;

use App\Models\PortalSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To the admin: a prospect sent something from their portal, or asked a question nobody has answered. */
class PortalActivity extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly PortalSubmission $submission) {}

    public function envelope(): Envelope
    {
        $prospect = $this->submission->prospect;
        $what = $this->submission->isWaitingForAnswer() ? 'asked a question that needs you' : mb_strtolower($this->submission->kindLabel());

        return new Envelope(subject: "{$prospect->company_name} {$what}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.portal.activity', with: [
            'submission' => $this->submission,
            'prospect' => $this->submission->prospect,
            'from' => $this->submission->invite?->name ?: $this->submission->invite?->email,
            'url' => route('admin.proposal-studio.prospects.show', $this->submission->prospect_id),
        ]);
    }
}
