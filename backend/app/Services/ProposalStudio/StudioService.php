<?php

namespace App\Services\ProposalStudio;

use App\Models\Prospect;
use App\Models\ProspectMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What an admin can do with a prospect's conversation. Turns are only queued
 * here; proposal-studio:work answers them (JobQueue).
 */
class StudioService
{
    public function __construct(private JobQueue $queue) {}

    /** @param  array<string, mixed>  $attributes */
    public function start(User $user, array $attributes, string $intake): Prospect
    {
        $this->guardRate($user);

        return DB::transaction(function () use ($user, $attributes, $intake) {
            // Every opportunity belongs to a business account: a new company gets one.
            $attributes['business_account_id'] ??= app(AccountService::class)->createFromProspect($attributes, $user)->id;
            $prospect = Prospect::create($attributes + ['created_by' => $user->id, 'status' => 'discovery']);
            $this->addMessage($user, $prospect, $intake);

            return $prospect;
        });
    }

    public function message(User $user, Prospect $prospect, string $body): void
    {
        $this->guardTurn($user, $prospect);

        DB::transaction(fn () => $this->addMessage($user, $prospect, $body));
    }

    public function draft(User $user, Prospect $prospect): void
    {
        $this->guardTurn($user, $prospect);
        $this->queue->dispatch(JobQueue::PROSPECT_DRAFT, $prospect, $user);
    }

    private function addMessage(User $user, Prospect $prospect, string $body): void
    {
        $prospect->messages()->create([
            'role' => ProspectMessage::ROLE_USER,
            'user_id' => $user->id,
            'body' => $body,
        ]);
        $this->queue->dispatch(JobQueue::PROSPECT_REPLY, $prospect, $user);
    }

    public function guardTurn(User $user, Prospect $prospect): void
    {
        if ($prospect->isAssistantBusy()) {
            throw new StudioRefused('The assistant is still working on this prospect. Wait for it to finish first.');
        }

        $this->guardRate($user);
    }

    private function guardRate(User $user): void
    {
        $limit = (int) config('proposal-studio.hourly_limit');
        $sent = ProspectMessage::where('user_id', $user->id)
            ->where('role', ProspectMessage::ROLE_USER)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($sent >= $limit) {
            throw new StudioRefused("You have sent {$limit} messages in the last hour, which is the limit. Please try again a little later.");
        }
    }
}
