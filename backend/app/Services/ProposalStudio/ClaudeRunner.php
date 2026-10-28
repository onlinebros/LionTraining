<?php

namespace App\Services\ProposalStudio;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * One Proposal Studio turn through the Claude Code CLI, on the owner's
 * subscription. Unlike the Request Assistant it reads nothing: the session
 * has no tools at all, so everything it knows is in the prompt.
 *
 *  - --tools "" : no tools, so no file, shell or web access to guard.
 *  - --setting-sources "" and --strict-mcp-config: none of the account's
 *    settings, hooks, CLAUDE.md files or MCP servers.
 *  - env -i: HOME, PATH and LANG only. No database password or API key, so
 *    billing cannot move off the subscription.
 *  - Started in an empty directory outside the repository, with no session
 *    kept: the prompt carries the whole conversation every time.
 */
class ClaudeRunner
{
    /**
     * @return array{text: string, meta: array<string, mixed>}
     */
    public function run(string $system, string $prompt): array
    {
        $workdir = (string) config('proposal-studio.workdir');
        File::ensureDirectoryExists($workdir);

        $result = Process::path($workdir)
            ->timeout((int) config('proposal-studio.timeout_seconds'))
            ->input($prompt)
            ->run($this->command($system));

        $decoded = json_decode($result->output(), true);
        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf(
                'The assistant did not answer (exit %s): %s',
                $result->exitCode() ?? '?',
                mb_substr(trim($result->errorOutput() ?: $result->output()), 0, 500),
            ));
        }

        $text = trim((string) ($decoded['result'] ?? ''));
        if ($text === '' || ($decoded['is_error'] ?? false) === true) {
            throw new RuntimeException('The assistant returned an error: '.mb_substr($text ?: (string) ($decoded['subtype'] ?? ''), 0, 500));
        }

        return [
            'text' => $text,
            'meta' => array_filter([
                'model' => config('proposal-studio.model'),
                'cost_usd' => $decoded['total_cost_usd'] ?? null,
                'duration_ms' => $decoded['duration_ms'] ?? null,
            ], fn ($v) => $v !== null),
        ];
    }

    /** @return list<string> */
    public function command(string $system): array
    {
        return [
            '/usr/bin/env', '-i',
            'HOME='.config('proposal-studio.claude_home'),
            'PATH=/usr/local/bin:/usr/bin:/bin',
            'LANG=C.UTF-8',
            'CLAUDE_CODE_DISABLE_AUTO_MEMORY=1',
            'CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC=1',
            (string) config('proposal-studio.claude_bin'),
            '-p',
            '--output-format', 'json',
            '--model', (string) config('proposal-studio.model'),
            '--max-turns', '3',
            '--tools', '',
            '--permission-mode', 'dontAsk',
            '--setting-sources', '',
            '--strict-mcp-config',
            '--disable-slash-commands',
            '--no-session-persistence',
            '--system-prompt', $system,
        ];
    }
}
