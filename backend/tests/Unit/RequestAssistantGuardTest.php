<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The guard hook is the boundary that keeps the Request Assistant inside its
 * copy of the project. Run as the CLI runs it: JSON on stdin, exit 0 or 2.
 */
class RequestAssistantGuardTest extends TestCase
{
    private static string $root;

    private static string $outside;

    public static function setUpBeforeClass(): void
    {
        $base = sys_get_temp_dir().'/ra-guard-'.getmypid();
        self::$root = $base.'/workspace';
        self::$outside = $base.'/outside.txt';
        @mkdir(self::$root.'/backend/app', 0777, true);
        file_put_contents(self::$root.'/backend/app/User.php', '<?php');
        file_put_contents(self::$outside, 'secret');
        @symlink(self::$outside, self::$root.'/escape.txt');
    }

    public static function tearDownAfterClass(): void
    {
        exec('rm -rf '.escapeshellarg(dirname(self::$root)));
    }

    private function guard(string $stdin): int
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2).'/scripts/request-assistant-guard.php', self::$root],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        return proc_close($process);
    }

    private function call(string $tool, array $input): string
    {
        return json_encode(['tool_name' => $tool, 'tool_input' => $input]);
    }

    public function test_reads_inside_the_workspace_are_allowed(): void
    {
        $this->assertSame(0, $this->guard($this->call('Read', ['file_path' => self::$root.'/backend/app/User.php'])));
        $this->assertSame(0, $this->guard($this->call('Read', ['file_path' => 'backend/app/User.php'])));
        $this->assertSame(0, $this->guard($this->call('Grep', ['pattern' => '\.\./', 'path' => 'backend'])));
        $this->assertSame(0, $this->guard($this->call('Glob', ['pattern' => '**/*.php'])));
        $this->assertSame(0, $this->guard($this->call('Read', ['file_path' => 'backend/.env.example'])));
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function escapes(): array
    {
        return [
            'absolute path' => ['Read', ['file_path' => '/etc/passwd']],
            'dot-dot' => ['Read', ['file_path' => 'backend/../../outside.txt']],
            'home' => ['Read', ['file_path' => '~/.claude/.credentials.json']],
            'sibling prefix' => ['Read', ['file_path' => '/WORKSPACE-2/x']],
            'symlink out' => ['Read', ['file_path' => 'escape.txt']],
            'env file' => ['Read', ['file_path' => 'backend/.env']],
            'env variant' => ['Read', ['file_path' => 'backend/.env.production']],
            'grep elsewhere' => ['Grep', ['pattern' => 'APP_KEY', 'path' => '/srv']],
            'grep glob dot-dot' => ['Grep', ['pattern' => 'x', 'glob' => '../**']],
            'glob absolute' => ['Glob', ['pattern' => '/etc/*']],
            'glob brace dot-dot' => ['Glob', ['pattern' => '{src,..}/*']],
            'glob path elsewhere' => ['Glob', ['pattern' => '*', 'path' => '/home']],
            'non-string path' => ['Read', ['file_path' => ['/etc']]],
            'shell' => ['Bash', ['command' => 'cat /etc/passwd']],
            'write' => ['Write', ['file_path' => 'backend/x.php', 'content' => 'x']],
            'web' => ['WebFetch', ['url' => 'https://example.com']],
        ];
    }

    #[DataProvider('escapes')]
    public function test_everything_else_is_blocked(string $tool, array $input): void
    {
        array_walk_recursive($input, function (&$value) {
            $value = is_string($value) ? str_replace('/WORKSPACE', self::$root, $value) : $value;
        });

        $this->assertSame(2, $this->guard($this->call($tool, $input)));
    }

    public function test_it_fails_closed(): void
    {
        $this->assertSame(2, $this->guard('not json'));
        $this->assertSame(2, $this->guard(''));
    }
}
