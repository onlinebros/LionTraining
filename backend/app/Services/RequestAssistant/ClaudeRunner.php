<?php

namespace App\Services\RequestAssistant;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Runs one turn of the Request Assistant through the Claude Code CLI.
 *
 * The guardrails, every one of which holds on its own:
 *
 *  1. Tools: only Read, Grep and Glob exist in the session (--tools). There is
 *     no shell, no editing, no web access, no MCP servers, no skills.
 *  2. Guard hook: every tool call passes through
 *     scripts/request-assistant-guard.php, which refuses any path outside the
 *     workspace, any .env file, and any tool it does not know. It fails closed.
 *  3. Permission mode dontAsk: anything not pre-approved is refused rather than
 *     asked about, and the CLI does not pre-approve reads outside its cwd.
 *  4. No inherited configuration: --setting-sources "" ignores the account's
 *     own settings, hooks, permissions and CLAUDE.md files, and auto-memory is
 *     off, so the owner's notes about the server never reach the session.
 *  5. Clean environment: the CLI starts under `env -i` with HOME, PATH and
 *     LANG only. The worker's database password and API keys are not passed.
 *  6. The workspace itself is a git-archive copy with no secrets and no
 *     symlinks (see Workspace).
 */
class ClaudeRunner
{
    public const TOOLS = 'Read,Grep,Glob';

    public function __construct(private Workspace $workspace) {}

    /**
     * @param  ?string  $system  the role for this turn; the Request Assistant's by default
     * @return array{text: string, session_id: ?string, meta: array<string, mixed>}
     */
    public function run(string $prompt, string $sessionId, bool $resume, ?string $system = null): array
    {
        $workspace = $this->workspace->path();

        $result = Process::path($workspace)
            ->timeout((int) config('request-assistant.timeout_seconds'))
            ->input($prompt)
            ->run($this->command($sessionId, $resume, $system));

        $decoded = json_decode($result->output(), true);
        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf(
                'The assistant did not answer (exit %s): %s',
                $result->exitCode() ?? '?',
                mb_substr(trim($result->errorOutput() ?: $result->output()), 0, 500),
            ));
        }

        $text = trim((string) ($decoded['result'] ?? ''));
        $subtype = (string) ($decoded['subtype'] ?? '');
        if ($text === '' || ($decoded['is_error'] ?? false) === true) {
            throw new RuntimeException($subtype === 'error_max_turns'
                ? 'The assistant ran out of steps before it finished. Try asking a narrower question.'
                : 'The assistant returned an error: '.mb_substr($text ?: $subtype, 0, 500));
        }

        return [
            'text' => $text,
            'session_id' => $decoded['session_id'] ?? null,
            'meta' => array_filter([
                'model' => config('request-assistant.model'),
                'cost_usd' => $decoded['total_cost_usd'] ?? null,
                'turns' => $decoded['num_turns'] ?? null,
                'duration_ms' => $decoded['duration_ms'] ?? null,
                'denied_tool_calls' => count($decoded['permission_denials'] ?? []) ?: null,
            ], fn ($v) => $v !== null),
        ];
    }

    /** @return list<string> */
    public function command(string $sessionId, bool $resume, ?string $system = null): array
    {
        $workspace = $this->workspace->path();

        $settings = [
            'hooks' => [
                'PreToolUse' => [[
                    'matcher' => '*',
                    'hooks' => [[
                        'type' => 'command',
                        'command' => implode(' ', array_map('escapeshellarg', [
                            PHP_BINARY,
                            base_path('scripts/request-assistant-guard.php'),
                            $workspace,
                        ])),
                    ]],
                ]],
            ],
        ];

        return [
            '/usr/bin/env', '-i',
            'HOME='.config('request-assistant.claude_home'),
            'PATH=/usr/local/bin:/usr/bin:/bin',
            'LANG=C.UTF-8',
            // git, run by the CLI for its own context, must not climb out of the
            // workspace into the real repository and report on it.
            'GIT_CEILING_DIRECTORIES='.dirname($workspace),
            'CLAUDE_CODE_DISABLE_AUTO_MEMORY=1',
            'CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC=1',
            (string) config('request-assistant.claude_bin'),
            '-p',
            '--output-format', 'json',
            '--model', (string) config('request-assistant.model'),
            '--max-turns', (string) config('request-assistant.max_turns'),
            '--tools', self::TOOLS,
            '--allowedTools', self::TOOLS,
            '--permission-mode', 'dontAsk',
            '--setting-sources', '',
            '--settings', json_encode($settings, JSON_UNESCAPED_SLASHES),
            '--strict-mcp-config',
            '--disable-slash-commands',
            '--append-system-prompt', $system ?? Prompts::system(),
            ...($resume ? ['--resume', $sessionId] : ['--session-id', $sessionId]),
        ];
    }
}
