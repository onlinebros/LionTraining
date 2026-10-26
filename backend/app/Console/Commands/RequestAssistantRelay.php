<?php

namespace App\Console\Commands;

use App\Models\SupportAssist;
use App\Services\RequestAssistant\ClaudeRunner;
use App\Services\RequestAssistant\Upstream;
use App\Services\RequestAssistant\Workspace;
use App\Services\SupportAssistant\Prompts as SupportPrompts;
use App\Services\SupportAssistant\TurnService as SupportTurnService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Dev's half of the Request Assistant: collect a waiting turn from the live
 * site, answer it on this server's Claude CLI (the owner's subscription), and
 * send the answer back. Dev always calls out; nothing calls dev. The turns are
 * change requests and Q3-AI questions on support tickets; dev's own support
 * tickets are answered here too, so the assistant can be tried on dev.
 *
 * Run as the user the CLI is logged in as, by scripts/request-assistant-worker.sh.
 */
class RequestAssistantRelay extends Command
{
    protected $signature = 'request-assistant:relay {--once : Answer at most one turn, then stop} {--max-time=3600 : Seconds before exiting, so code changes are picked up}';

    protected $description = 'Answer the Request Assistant turns waiting on the live site';

    public function handle(Workspace $workspace, ClaudeRunner $runner, Upstream $upstream, SupportTurnService $supportTurns): int
    {
        $deadline = time() + (int) $this->option('max-time');

        do {
            if (! Upstream::isConnected()) {
                $this->line('Not connected (request-assistant:connect). Waiting.');
                if ($this->option('once')) {
                    return self::FAILURE;
                }
                sleep(30);

                continue;
            }

            try {
                $answered = $this->relayOne($workspace, $runner, $upstream, $supportTurns);
            } catch (Throwable $e) {
                $this->error(now()->toDateTimeString().' '.$e->getMessage());
                Log::warning('Request Assistant relay', ['error' => $e->getMessage()]);
                $answered = false;
                sleep(15);
            }

            if ($this->option('once')) {
                return self::SUCCESS;
            }
            // Idle: one short query on the live site every five seconds.
            if (! $answered) {
                sleep(5);
            }
        } while (time() < $deadline);

        return self::SUCCESS;
    }

    private function relayOne(Workspace $workspace, ClaudeRunner $runner, Upstream $upstream, SupportTurnService $supportTurns): bool
    {
        $commit = $workspace->refresh();

        // This server's own support tickets first: on dev, Q3-AI answers the
        // tickets kept here as well as the live site's.
        $local = $supportTurns->claim($commit);
        if ($local !== null) {
            $assist = SupportAssist::findOrFail($local['id']);
            $this->answer($local, $runner,
                fn (array $answer) => $supportTurns->complete($assist, $answer, $commit),
                fn (string $error) => $supportTurns->fail($assist, $error),
                'local ');

            return true;
        }

        $turn = $upstream->call('post', 'relay/claim', ['commit' => $commit, 'kinds' => [SupportTurnService::KIND]])['turn'] ?? null;
        if (! is_array($turn)) {
            return false;
        }

        $path = ($turn['kind'] ?? null) === SupportTurnService::KIND ? "relay/support/{$turn['id']}" : "relay/{$turn['id']}";
        $this->answer($turn, $runner,
            fn (array $answer) => $upstream->call('post', "{$path}/complete", [
                'mode' => $turn['mode'],
                'text' => $answer['text'],
                'session_id' => $answer['session_id'],
                'meta' => $answer['meta'],
                'commit' => $commit,
            ]),
            fn (string $error) => $upstream->call('post', "{$path}/fail", ['error' => $error]),
        );

        return true;
    }

    /**
     * Run one turn on the CLI, resuming its session where there is one, and
     * hand the answer or the failure on.
     *
     * @param  array{kind?: string, number: string, mode: string, session_id: ?string, prompt: string, fresh_prompt: string}  $turn
     */
    private function answer(array $turn, ClaudeRunner $runner, \Closure $complete, \Closure $fail, string $label = ''): void
    {
        $this->line(now()->toDateTimeString()." {$label}{$turn['number']} {$turn['mode']}");
        $started = microtime(true);
        $system = ($turn['kind'] ?? null) === SupportTurnService::KIND ? SupportPrompts::system() : null;

        try {
            try {
                $answer = $turn['session_id']
                    ? $runner->run($turn['prompt'], $turn['session_id'], true, $system)
                    : $runner->run($turn['prompt'], (string) Str::uuid(), false, $system);
            } catch (Throwable $e) {
                if (! $turn['session_id']) {
                    throw $e;
                }
                // The CLI's copy of the conversation is gone. Start a new one
                // from the record kept with the request or ticket.
                $this->line('  could not resume; starting fresh');
                $answer = $runner->run($turn['fresh_prompt'], (string) Str::uuid(), false, $system);
            }
        } catch (Throwable $e) {
            $fail($e->getMessage());
            $this->error("  failed: {$e->getMessage()}");

            return;
        }

        $complete($answer);
        $this->line(sprintf('  answered in %.0fs', microtime(true) - $started));
    }
}
