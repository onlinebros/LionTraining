<?php

namespace App\Services\RequestAssistant;

use App\Models\ChangeRequest;
use App\Models\ChangeRequestMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The live site's half of a turn. Dev claims a waiting turn and gets the
 * prompts to run; it answers on its own CLI and sends the result back, which
 * is recorded here. The prompts are built here, from the record this server
 * keeps, so dev holds nothing between turns but the CLI's own session.
 */
class TurnService
{
    public const MODE_REPLY = 'reply';

    public const MODE_PLAN = 'plan';

    /**
     * The oldest waiting turn, handed out once.
     *
     * @param  string  $commit  the commit dev's project copy is at
     * @return ?array{id: int, number: string, mode: string, session_id: ?string, prompt: string, fresh_prompt: string}
     */
    public function claim(string $commit): ?array
    {
        $changeRequest = DB::transaction(function () {
            $changeRequest = ChangeRequest::whereNotNull('assistant_pending')
                ->where(fn ($q) => $q->whereNull('assistant_claimed_at')
                    // A turn dev took and never answered (it restarted) is offered again.
                    ->orWhere('assistant_claimed_at', '<', now()->subMinutes(ChangeRequest::BUSY_STALE_MINUTES)))
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            $changeRequest?->forceFill(['assistant_claimed_at' => now(), 'assistant_busy_since' => now()])->save();

            return $changeRequest;
        });

        if ($changeRequest === null) {
            return null;
        }

        $changeRequest->load('user');
        $mode = (string) $changeRequest->assistant_pending;
        $resume = $changeRequest->claude_session_id !== null;

        $turn = Prompts::turn($changeRequest, ! $resume, $changeRequest->snapshot_commit, $commit);
        $fresh = Prompts::turn($changeRequest, true, null, $commit);
        if ($mode === self::MODE_PLAN) {
            $turn = Prompts::plan($changeRequest);
            $fresh .= "\n\n".Prompts::plan($changeRequest);
        }

        return [
            'id' => $changeRequest->id,
            'number' => $changeRequest->number,
            'mode' => $mode,
            'session_id' => $changeRequest->claude_session_id,
            'prompt' => $turn,
            // For when the CLI cannot resume its session: the whole
            // conversation from this server's record.
            'fresh_prompt' => $fresh,
        ];
    }

    /** @param  array{text: string, session_id: ?string, meta?: array<string, mixed>}  $answer */
    public function complete(ChangeRequest $changeRequest, string $mode, array $answer, string $commit): void
    {
        DB::transaction(function () use ($changeRequest, $mode, $answer, $commit) {
            if ($mode === self::MODE_PLAN) {
                $changeRequest->forceFill(['plan' => self::planOnly($answer['text']), 'plan_drafted_at' => now()]);
                $body = 'I have drafted the plan. It is in the Plan panel. Read it through, tell me what to change, or submit it for review when it looks right.';
            } else {
                $body = $answer['text'];
            }

            $changeRequest->messages()->create([
                'role' => ChangeRequestMessage::ROLE_ASSISTANT,
                'body' => $body,
                'meta' => ($answer['meta'] ?? []) + ['mode' => $mode],
            ]);

            $changeRequest->forceFill([
                'claude_session_id' => $answer['session_id'] ?? $changeRequest->claude_session_id,
                'snapshot_commit' => $commit,
            ])->save();
            $this->finish($changeRequest);
        });
    }

    public function fail(ChangeRequest $changeRequest, string $error): void
    {
        Log::error('Request Assistant reply failed', ['change_request' => $changeRequest->number, 'error' => $error]);

        $changeRequest->messages()->create([
            'role' => ChangeRequestMessage::ROLE_ERROR,
            'body' => 'The assistant could not answer this time. Please try again in a minute. If it keeps happening, let the team know.',
            'meta' => ['error' => mb_substr($error, 0, 1000)],
        ]);
        $this->finish($changeRequest);
    }

    // The plan starts at its first heading; anything before it is the model
    // clearing its throat ("Here is the plan.").
    public static function planOnly(string $text): string
    {
        $start = strpos($text, '## ');

        return trim($start === false ? $text : substr($text, $start));
    }

    private function finish(ChangeRequest $changeRequest): void
    {
        $changeRequest->forceFill([
            'assistant_pending' => null,
            'assistant_claimed_at' => null,
            'assistant_busy_since' => null,
        ])->save();
    }
}
