<?php

namespace App\Services\SupportAssistant;

use App\Http\Middleware\RequireRequestAssistantToken;
use App\Models\ChangeRequest;
use App\Models\SiteSetting;
use App\Models\SupportAssist;
use App\Models\SupportAssistMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\RequestAssistant\ChangeRequestService;
use App\Services\RequestAssistant\RequestRefused;
use App\Services\RequestAssistant\Upstream;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What support staff can do with the Q3-AI Assistant on a ticket. A turn is
 * only marked as waiting here; the Request Assistant's relay on dev answers it
 * (TurnService). Any admin who can open the ticket can ask.
 */
class SupportAssistService
{
    public const DEFAULT_ASK = 'Draft a reply to this ticket, and tell me whether it needs something the system does not do yet.';

    public function __construct(private ChangeRequestService $changeRequests) {}

    /**
     * Whether a relay will answer here: on dev, the relay runs and answers
     * dev's own tickets once it is connected; on the live site, once a token
     * has been issued for dev. Elsewhere a question would wait forever.
     */
    public static function available(): bool
    {
        return Upstream::isConnected()
            || filled(SiteSetting::get(RequireRequestAssistantToken::SETTING));
    }

    public function ask(User $user, SupportTicket $ticket, ?string $note): SupportAssist
    {
        if (! self::available()) {
            throw new RequestRefused('Q3-AI is not connected on this server.');
        }

        $assist = SupportAssist::firstOrCreate(['ticket_id' => $ticket->id]);
        if ($assist->isBusy()) {
            throw new RequestRefused('Q3-AI is still answering. Wait for its reply first.');
        }
        $this->guardRate($user);

        DB::transaction(function () use ($user, $assist, $note) {
            $assist->messages()->create([
                'role' => SupportAssistMessage::ROLE_USER,
                'user_id' => $user->id,
                'body' => filled($note) ? $note : self::DEFAULT_ASK,
            ]);
            $assist->forceFill([
                'pending' => TurnService::MODE_REPLY,
                'claimed_at' => null,
                'busy_since' => now(),
            ])->save();
        });

        return $assist;
    }

    /**
     * Hands Q3-AI's finding to the Request Assistant as a new change request,
     * so it can be worked into a plan and reviewed. Super admins only, as for
     * every change request. The ticket goes in without the member's identity.
     */
    public function startChangeRequest(User $user, SupportTicket $ticket): ChangeRequest
    {
        if (! $user->isSuperAdmin()) {
            throw new RequestRefused('Only super admins can start change requests.');
        }

        $assist = $ticket->assist()->with('messages', 'changeRequest')->first();
        if ($assist?->changeRequest) {
            return $assist->changeRequest;
        }
        $answer = $assist?->latestAnswer();
        if ($answer === null || $answer->verdict() !== 'not_built') {
            throw new RequestRefused('Q3-AI has not found anything here that needs building.');
        }

        $redact = new Redactor($ticket);
        $body = implode("\n\n", array_filter([
            "From support ticket {$ticket->ticket_number} ({$ticket->subject}).",
            $answer->section('change') ?? $answer->meta['verdict_reason'] ?? null,
            ($today = $answer->section('today')) ? "How it works today, according to Q3-AI:\n{$today}" : null,
        ]));

        $changeRequest = $this->changeRequests->start(
            $user,
            Str::limit("{$ticket->ticket_number}: ".$redact->text($ticket->subject), 195),
            mb_substr($redact->text($body), 0, 10000),
            ['url' => route('admin.support.show', $ticket), 'title' => "Support ticket {$ticket->ticket_number}"],
        );
        $assist->forceFill(['change_request_id' => $changeRequest->id])->save();

        return $changeRequest;
    }

    private function guardRate(User $user): void
    {
        $limit = (int) config('request-assistant.support_hourly_limit');
        $sent = SupportAssistMessage::where('user_id', $user->id)
            ->where('role', SupportAssistMessage::ROLE_USER)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($sent >= $limit) {
            throw new RequestRefused("You have asked Q3-AI {$limit} times in the last hour, which is the limit. Please try again a little later.");
        }
    }
}
