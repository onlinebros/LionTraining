<?php

namespace App\Console\Commands;

use App\Services\RequestAssistant\ClaudeRunner;
use App\Services\RequestAssistant\Upstream;
use App\Services\RequestAssistant\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Dev's half of the Request Assistant: collect a waiting turn from the live
 * site, answer it on this server's Claude CLI (the owner's subscription), and
 * send the answer back. Dev always calls out; nothing calls dev.
 *
 * Run as the user the CLI is logged in as, by scripts/request-assistant-worker.sh.
 */
class RequestAssistantRelay extends Command
{
    protected $signature = 'request-assistant:relay {--once : Answer at most one turn, then stop} {--max-time=3600 : Seconds before exiting, so code changes are picked up}';

    protected $description = 'Answer the Request Assistant turns waiting on the live site';

    public function handle(Workspace $workspace, ClaudeRunner $runner, Upstream $upstream): int
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
                $answered = $this->relayOne($workspace, $runner, $upstream);
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

    private function relayOne(Workspace $workspace, ClaudeRunner $runner, Upstream $upstream): bool
    {
        $commit = $workspace->refresh();
        $turn = $upstream->call('post', 'relay/claim', ['commit' => $commit])['turn'] ?? null;
        if (! is_array($turn)) {
            return false;
        }

        $this->line(now()->toDateTimeString()." {$turn['number']} {$turn['mode']}");
        $started = microtime(true);

        try {
            try {
                $answer = $turn['session_id']
                    ? $runner->run($turn['prompt'], $turn['session_id'], true)
                    : $runner->run($turn['prompt'], (string) Str::uuid(), false);
            } catch (Throwable $e) {
                if (! $turn['session_id']) {
                    throw $e;
                }
                // The CLI's copy of the conversation is gone. Start a new one
                // from the live site's record of it.
                $this->line('  could not resume; starting fresh');
                $answer = $runner->run($turn['fresh_prompt'], (string) Str::uuid(), false);
            }
        } catch (Throwable $e) {
            $upstream->call('post', "relay/{$turn['id']}/fail", ['error' => $e->getMessage()]);
            $this->error("  failed: {$e->getMessage()}");

            return true;
        }

        $upstream->call('post', "relay/{$turn['id']}/complete", [
            'mode' => $turn['mode'],
            'text' => $answer['text'],
            'session_id' => $answer['session_id'],
            'meta' => $answer['meta'],
            'commit' => $commit,
        ]);
        $this->line(sprintf('  answered in %.0fs', microtime(true) - $started));

        return true;
    }
}
