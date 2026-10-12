<?php

namespace App\Services\RequestAssistant;

use App\Models\ChangeRequest;
use App\Models\ChangeRequestMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What a requester (a super admin) can do with change requests, on the server
 * that keeps them (the live site). A turn for the assistant is only marked as
 * waiting here; dev collects it, answers it and sends the reply back
 * (TurnService). Every rule a requester is held to lives here.
 */
class ChangeRequestService
{
    /** @param  ?array{url: string, title: ?string}  $source  the page it was started from */
    public function start(User $user, string $title, string $body, ?array $source = null): ChangeRequest
    {
        $this->guardRate($user);

        return DB::transaction(function () use ($user, $title, $body, $source) {
            $changeRequest = ChangeRequest::create([
                'user_id' => $user->id,
                'title' => $title,
                'status' => 'drafting',
                'source_url' => self::cleanUrl($source['url'] ?? null),
                'source_title' => isset($source['title']) ? mb_substr($source['title'], 0, 255) : null,
            ]);
            $changeRequest->messages()->create([
                'role' => ChangeRequestMessage::ROLE_USER,
                'user_id' => $user->id,
                'body' => $body,
            ]);
            $this->queueTurn($changeRequest, TurnService::MODE_REPLY);

            return $changeRequest;
        });
    }

    public function message(User $user, ChangeRequest $changeRequest, string $body): void
    {
        $this->guardTurn($user, $changeRequest);

        DB::transaction(function () use ($user, $changeRequest, $body) {
            $changeRequest->messages()->create([
                'role' => ChangeRequestMessage::ROLE_USER,
                'user_id' => $user->id,
                'body' => $body,
            ]);
            $this->queueTurn($changeRequest, TurnService::MODE_REPLY);
        });
    }

    public function draftPlan(User $user, ChangeRequest $changeRequest): void
    {
        $this->guardTurn($user, $changeRequest);
        $this->queueTurn($changeRequest, TurnService::MODE_PLAN);
    }

    /** @return string what to tell the requester */
    public function submit(User $user, ChangeRequest $changeRequest): string
    {
        $this->guardOwner($user, $changeRequest);

        if (! in_array($changeRequest->status, ['drafting', 'needs_info'], true)) {
            throw new RequestRefused('This request has already been submitted.');
        }
        if (blank($changeRequest->plan)) {
            throw new RequestRefused('Draft the plan first. The team reviews the plan, not the conversation alone.');
        }

        $changeRequest->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();

        return "{$changeRequest->number} is with the team for review. You can keep adding detail here while it waits.";
    }

    /** Scheme, host and path only: a query string can carry a signed link or a token. */
    public static function cleanUrl(?string $url): ?string
    {
        $parts = $url ? parse_url($url) : false;
        if (! $parts || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '/');
    }

    private function queueTurn(ChangeRequest $changeRequest, string $mode): void
    {
        $changeRequest->forceFill([
            'assistant_pending' => $mode,
            'assistant_claimed_at' => null,
            'assistant_busy_since' => now(),
        ])->save();
    }

    private function guardOwner(User $user, ChangeRequest $changeRequest): void
    {
        if ($changeRequest->user_id !== $user->id) {
            throw new RequestRefused('Only the person who started this request can continue it.');
        }
    }

    private function guardTurn(User $user, ChangeRequest $changeRequest): void
    {
        $this->guardOwner($user, $changeRequest);

        if (! $changeRequest->isOpenForConversation()) {
            throw new RequestRefused('This request has been decided, so the conversation is closed. Start a new request for anything further.');
        }
        if ($changeRequest->isAssistantBusy()) {
            throw new RequestRefused('The assistant is still answering. Wait for its reply first.');
        }

        $this->guardRate($user);
    }

    private function guardRate(User $user): void
    {
        $limit = (int) config('request-assistant.hourly_limit');
        $sent = ChangeRequestMessage::where('user_id', $user->id)
            ->where('role', ChangeRequestMessage::ROLE_USER)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($sent >= $limit) {
            throw new RequestRefused("You have sent {$limit} messages in the last hour, which is the limit. Please try again a little later.");
        }
    }
}
