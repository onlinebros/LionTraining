<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Stripe needs one more thing before we can pay you."
 *
 * Names the actual items: "finish your setup" gets ignored by someone who
 * believes they already did, "we need your date of birth" does not. Links to
 * the Get Paid page rather than to Stripe, because the embedded form there is
 * the only place these accounts can be completed.
 */
class ConnectInformationNeeded extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $items  Plain-words labels, already summarised.
     * @param  bool  $urgent  Payouts are blocked now, rather than at a future threshold.
     */
    public function __construct(
        public readonly array $items,
        public readonly bool $urgent = false,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $list = $this->items ? implode(', ', $this->items) : 'a few remaining details';

        return (new MailMessage)
            ->subject($this->urgent ? 'Action needed to get paid' : 'One more detail for your payout account')
            ->greeting('Hi '.strtok((string) $notifiable->name, ' ').',')
            ->line($this->urgent
                ? "Stripe needs your {$list} before we can send your commissions."
                : "Stripe will need your {$list} to keep your payouts running. Adding it now stops your commissions being held later.")
            ->action('Open Get Paid', route('member.payouts.index'))
            ->line('It takes a couple of minutes on the Get Paid page in your Q3 back office. We never ask for these details by email or phone.');
    }
}
