<?php

declare(strict_types=1);

namespace Tests\Unit\EventTaxonomy;

use PHPUnit\Framework\TestCase;

/**
 * Asserts that the on-disk generated classes match what the codegen would
 * produce *right now* from the current taxonomy. CI runs this same gate via
 * `php scripts/codegen-events.php --check`; the test gives developers the
 * same failure mode from `php artisan test` without needing to remember the
 * codegen incantation.
 */
final class CodegenDriftTest extends TestCase
{
    public function test_codegen_check_mode_reports_no_drift(): void
    {
        $root = dirname(__DIR__, 3);
        $script = $root.'/scripts/codegen-events.php';
        $this->assertFileExists($script);

        $process = proc_open(
            [PHP_BINARY, $script, '--check'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );

        $this->assertIsResource($process, 'Failed to spawn codegen --check');

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(
            0,
            $exitCode,
            "Event taxonomy codegen drift detected. Run `php scripts/codegen-events.php`.\nstdout:\n{$stdout}\nstderr:\n{$stderr}",
        );
    }
}
