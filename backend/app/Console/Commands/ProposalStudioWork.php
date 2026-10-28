<?php

namespace App\Console\Commands;

use App\Services\ProposalStudio\ClaudeRunner;
use App\Services\ProposalStudio\JobQueue;
use App\Services\ProposalStudio\Upstream;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Answers Proposal Studio jobs on this server's Claude CLI (the owner's
 * subscription): this server's own, and the live site's when connected
 * (proposal-studio:connect). Run as the user the CLI is logged in as, by
 * scripts/proposal-studio-worker.sh.
 */
class ProposalStudioWork extends Command
{
    protected $signature = 'proposal-studio:work {--once : Answer at most one job, then stop} {--max-time=3600 : Seconds before exiting, so code changes are picked up}';

    protected $description = 'Answer the Proposal Studio jobs waiting for the assistant';

    public function handle(JobQueue $queue, ClaudeRunner $runner, Upstream $upstream): int
    {
        if (! config('proposal-studio.enabled')) {
            $this->line('The Proposal Studio is off here (PROPOSAL_STUDIO_ENABLED).');

            return self::FAILURE;
        }

        $deadline = time() + (int) $this->option('max-time');

        do {
            $answered = false;
            try {
                $answered = $this->answerLocal($queue, $runner);
            } catch (Throwable $e) {
                $this->report('local', $e);
            }
            try {
                if (! $answered && Upstream::isConnected()) {
                    $answered = $this->answerUpstream($upstream, $runner);
                }
            } catch (Throwable $e) {
                $this->report('upstream', $e);
                sleep(15);
            }

            if ($this->option('once')) {
                return self::SUCCESS;
            }
            if (! $answered) {
                sleep(3);
            }
        } while (time() < $deadline);

        return self::SUCCESS;
    }

    private function answerLocal(JobQueue $queue, ClaudeRunner $runner): bool
    {
        $claimed = $queue->claim();
        if ($claimed === null) {
            return false;
        }

        $job = $claimed['job'];
        $this->line(now()->toDateTimeString()." job {$job->id} {$job->kind}");
        $started = microtime(true);

        try {
            $answer = $runner->run($claimed['system'], $claimed['prompt']);
        } catch (Throwable $e) {
            $queue->fail($job, $e->getMessage());
            $this->error("  failed: {$e->getMessage()}");

            return true;
        }

        $queue->complete($job, $answer['text'], $answer['meta']);
        $this->line(sprintf('  %s in %.0fs', $job->fresh()?->status, microtime(true) - $started));

        return true;
    }

    private function answerUpstream(Upstream $upstream, ClaudeRunner $runner): bool
    {
        $job = $upstream->post('relay/claim')['job'] ?? null;
        if (! is_array($job)) {
            return false;
        }

        $this->line(now()->toDateTimeString()." live job {$job['id']} {$job['kind']}");
        $started = microtime(true);

        try {
            $answer = $runner->run((string) $job['system'], (string) $job['prompt']);
        } catch (Throwable $e) {
            $upstream->post("relay/{$job['id']}/fail", ['error' => mb_substr($e->getMessage(), 0, 5000)]);
            $this->error("  failed: {$e->getMessage()}");

            return true;
        }

        $result = $upstream->post("relay/{$job['id']}/complete", ['text' => $answer['text'], 'meta' => $answer['meta']]);
        $this->line(sprintf('  %s in %.0fs', $result['status'] ?? '?', microtime(true) - $started));

        return true;
    }

    private function report(string $where, Throwable $e): void
    {
        $this->error(now()->toDateTimeString()." {$where}: {$e->getMessage()}");
        Log::warning('Proposal Studio worker', ['where' => $where, 'error' => $e->getMessage()]);
    }
}
