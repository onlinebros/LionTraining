<?php

namespace App\Services\SupportAssistant;

use App\Models\ChangeRequest;
use App\Models\SupportAssist;
use App\Models\SupportAssistMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The half of a support turn kept with the tickets: hand a waiting turn out
 * once with its prompts, and record the answer or the failure. Called through
 * the Request Assistant API when dev answers for the live site, and directly
 * by the relay for dev's own tickets.
 */
class TurnService
{
    public const KIND = 'support';

    public const MODE_REPLY = 'reply';

    /**
     * @param  string  $commit  the commit dev's project copy is at
     * @return ?array{kind: string, id: int, number: string, mode: string, session_id: ?string, prompt: string, fresh_prompt: string}
     */
    public function claim(string $commit): ?array
    {
        $assist = DB::transaction(function () {
            $assist = SupportAssist::whereNotNull('pending')
                ->where(fn ($q) => $q->whereNull('claimed_at')
                    ->orWhere('claimed_at', '<', now()->subMinutes(ChangeRequest::BUSY_STALE_MINUTES)))
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            $assist?->forceFill(['claimed_at' => now(), 'busy_since' => now()])->save();

            return $assist;
        });

        if ($assist === null) {
            return null;
        }

        $assist->load('ticket');
        $resume = $assist->claude_session_id !== null;

        return [
            'kind' => self::KIND,
            'id' => $assist->id,
            'number' => $assist->ticket->ticket_number,
            'mode' => (string) $assist->pending,
            'session_id' => $assist->claude_session_id,
            'prompt' => Prompts::turn($assist, ! $resume, $assist->snapshot_commit, $commit),
            'fresh_prompt' => Prompts::turn($assist, true, null, $commit),
        ];
    }

    /** @param  array{text: string, session_id: ?string, meta?: array<string, mixed>}  $answer */
    public function complete(SupportAssist $assist, array $answer, string $commit): void
    {
        DB::transaction(function () use ($assist, $answer, $commit) {
            $assist->messages()->create([
                'role' => SupportAssistMessage::ROLE_ASSISTANT,
                'body' => $answer['text'],
                'meta' => ($answer['meta'] ?? []) + self::parse($answer['text']),
            ]);

            $assist->forceFill([
                'claude_session_id' => $answer['session_id'] ?? $assist->claude_session_id,
                'snapshot_commit' => $commit,
            ])->save();
            $this->finish($assist);
        });
    }

    public function fail(SupportAssist $assist, string $error): void
    {
        Log::error('Q3-AI support reply failed', ['support_assist' => $assist->id, 'error' => $error]);

        $assist->messages()->create([
            'role' => SupportAssistMessage::ROLE_ERROR,
            'body' => 'Q3-AI could not answer this time. Please try again in a minute. If it keeps happening, let the team know.',
            'meta' => ['error' => mb_substr($error, 0, 1000)],
        ]);
        $this->finish($assist);
    }

    /**
     * The verdict and sections of an answer written to the headings in
     * Prompts::system(). Anything it cannot find is left out, and the page
     * shows the answer as written.
     *
     * @return array{verdict?: string, verdict_reason?: string, sections: array<string, string>}
     */
    public static function parse(string $text): array
    {
        $keys = [
            'verdict' => 'verdict',
            'what the system does today' => 'today',
            'draft reply' => 'draft',
            'change needed' => 'change',
        ];

        $sections = [];
        $parts = preg_split('/^##\s+(.+?)\s*$/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $key = $keys[mb_strtolower(trim($parts[$i]))] ?? null;
            if ($key !== null && trim($parts[$i + 1]) !== '') {
                $sections[$key] = trim($parts[$i + 1]);
            }
        }

        $parsed = ['sections' => array_diff_key($sections, ['verdict' => true])];
        if (isset($sections['verdict']) && preg_match('/\b(ANSWERABLE|MANUAL_ACTION|NOT_BUILT)\b/', $sections['verdict'], $m)) {
            $parsed['verdict'] = strtolower($m[1]);
            $reason = trim((string) preg_replace('/^[\s*:.\-—–]+/u', '', str_replace($m[0], '', $sections['verdict'])));
            if ($reason !== '') {
                $parsed['verdict_reason'] = $reason;
            }
        }

        return $parsed;
    }

    private function finish(SupportAssist $assist): void
    {
        $assist->forceFill([
            'pending' => null,
            'claimed_at' => null,
            'busy_since' => null,
        ])->save();
    }
}
