<?php

declare(strict_types=1);

namespace Tests\Unit\EventTaxonomy;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the *static* gate end-to-end: runs PHPStan against the
 * intentionally-broken fixtures under tests/StaticGate/Fixtures/ and asserts
 * the analyser reports the expected argument-missing errors for placement_id
 * and affiliate_id. This is the test that catches regressions in the
 * codegen template (e.g. accidentally defaulting required fields to null).
 *
 * The fixtures directory is NOT in phpstan.neon's `paths`, so the main CI
 * analyse run stays green; this test runs PHPStan a second time *only* over
 * those fixtures and inverts the expectation (it should fail).
 */
final class StaticGateTest extends TestCase
{
    public function test_phpstan_rejects_event_constructed_without_required_fields(): void
    {
        $root = dirname(__DIR__, 3);
        $phpstan = $root.'/vendor/bin/phpstan';
        $fixtures = $root.'/tests/StaticGate/Fixtures';

        if (! is_executable($phpstan)) {
            $this->markTestSkipped('PHPStan binary not installed; run `composer install`.');
        }

        $process = proc_open(
            [
                PHP_BINARY,
                $phpstan,
                'analyse',
                $fixtures,
                '--no-progress',
                '--no-interaction',
                '--error-format=json',
            ],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );

        $this->assertIsResource($process, 'Failed to spawn phpstan');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertNotSame(
            0,
            $exitCode,
            "Expected PHPStan to fail on broken fixtures, but it exited 0.\nstdout:\n{$stdout}\nstderr:\n{$stderr}",
        );

        $this->assertNotSame(
            '',
            trim($stdout),
            "PHPStan produced no stdout when analysing broken fixtures.\nstderr:\n{$stderr}",
        );

        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded, "Could not decode PHPStan JSON output: {$stdout}");

        $allMessages = [];
        $allIdentifiers = [];
        foreach ($decoded['error_details'] ?? [] as $errors) {
            foreach ($errors as $msg) {
                $allMessages[] = $msg['message'] ?? '';
                $allIdentifiers[] = $msg['identifier'] ?? '';
            }
        }
        $joinedMessages = implode("\n", $allMessages);

        $this->assertStringContainsString(
            'placement_id',
            $joinedMessages,
            "PHPStan output did not mention missing placement_id:\nstdout:\n{$stdout}",
        );
        $this->assertStringContainsString(
            'affiliate_id',
            $joinedMessages,
            "PHPStan output did not mention missing affiliate_id:\nstdout:\n{$stdout}",
        );
        $this->assertContains(
            'argument.missing',
            $allIdentifiers,
            'PHPStan errors did not include identifier=argument.missing',
        );
    }
}
