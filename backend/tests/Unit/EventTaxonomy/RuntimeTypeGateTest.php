<?php

declare(strict_types=1);

namespace Tests\Unit\EventTaxonomy;

use App\Events\Taxonomy\BaseEvent;
use ArgumentCountError;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Runtime counterpart to the PHPStan static gate. For every event in the
 * taxonomy, asserts that the generated PHP class:
 *   1. exists and extends BaseEvent
 *   2. exposes every taxonomy field as a public constructor parameter with
 *      the right PHP type, and required fields have NO default value (so
 *      omitting them is an ArgumentCountError at runtime, not silently null)
 *   3. accepts a complete required-field-only construction without error
 *
 * This is the test that fails loudly the moment the codegen template is
 * weakened to accept defaults on required fields — even if PHPStan is
 * misconfigured.
 */
final class RuntimeTypeGateTest extends TestCase
{
    private const TAXONOMY_PATH = __DIR__.'/../../../config/events/taxonomy.v1.json';

    private const PHP_TYPE_MAP = [
        'string' => 'string',
        'int' => 'int',
        'bool' => 'bool',
        'iso8601' => 'string',
    ];

    /** @var array<string, mixed> */
    private array $taxonomy;

    protected function setUp(): void
    {
        parent::setUp();

        $raw = file_get_contents(self::TAXONOMY_PATH);
        $this->assertNotFalse($raw, 'Failed to read taxonomy file');
        $this->taxonomy = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_every_taxonomy_event_has_a_generated_class_extending_base_event(): void
    {
        $namespace = $this->taxonomy['namespace'];

        foreach ($this->taxonomy['events'] as $event) {
            $fqcn = $namespace.'\\'.$event['name'];
            $this->assertTrue(class_exists($fqcn), "Missing generated class {$fqcn}");

            $reflection = new ReflectionClass($fqcn);
            $this->assertTrue(
                $reflection->isSubclassOf(BaseEvent::class),
                "{$fqcn} does not extend BaseEvent",
            );
            $this->assertTrue(
                $reflection->isFinal(),
                "{$fqcn} must be final to lock the taxonomy contract",
            );
        }
    }

    public function test_required_fields_have_no_default_value(): void
    {
        $namespace = $this->taxonomy['namespace'];

        foreach ($this->taxonomy['events'] as $event) {
            $fqcn = $namespace.'\\'.$event['name'];
            $ctor = (new ReflectionClass($fqcn))->getConstructor();
            $this->assertNotNull($ctor, "{$fqcn} has no constructor");

            $params = [];
            foreach ($ctor->getParameters() as $p) {
                $params[$p->getName()] = $p;
            }

            foreach ($event['fields'] as $field) {
                $fName = $field['name'];
                $this->assertArrayHasKey(
                    $fName,
                    $params,
                    "{$fqcn} is missing ctor parameter for taxonomy field '{$fName}'",
                );

                $param = $params[$fName];
                $type = $param->getType();
                $this->assertNotNull($type, "{$fqcn}::\${$fName} has no PHP type declaration");

                $expectedType = self::PHP_TYPE_MAP[$field['type']];
                $this->assertStringContainsString(
                    $expectedType,
                    (string) $type,
                    "{$fqcn}::\${$fName} expected PHP type {$expectedType}, got ".(string) $type,
                );

                if ($field['required']) {
                    $this->assertFalse(
                        $param->isDefaultValueAvailable(),
                        "{$fqcn}::\${$fName} is taxonomy-required but has a default value — "
                        .'this would silently accept omission, breaking the type gate.',
                    );
                    $this->assertFalse(
                        $param->allowsNull(),
                        "{$fqcn}::\${$fName} is taxonomy-required but its type allows null — "
                        .'required fields must be non-nullable so a missing value is a type error.',
                    );
                } else {
                    $this->assertTrue(
                        $param->allowsNull(),
                        "{$fqcn}::\${$fName} is taxonomy-optional but its type does not allow null",
                    );
                }
            }
        }
    }

    public function test_omitting_required_field_throws_argument_count_error(): void
    {
        $namespace = $this->taxonomy['namespace'];

        foreach ($this->taxonomy['events'] as $event) {
            $fqcn = $namespace.'\\'.$event['name'];

            $requiredArgs = [];
            foreach ($event['fields'] as $field) {
                if ($field['required']) {
                    $requiredArgs[$field['name']] = $this->sampleValue($field['type']);
                }
            }

            // Sanity: full required-only construction succeeds.
            $instance = (new ReflectionClass($fqcn))->newInstanceArgs($requiredArgs);
            $this->assertInstanceOf(BaseEvent::class, $instance);

            // Drop each required field one at a time and confirm the runtime rejects it.
            foreach (array_keys($requiredArgs) as $drop) {
                $partial = $requiredArgs;
                unset($partial[$drop]);

                try {
                    (new ReflectionClass($fqcn))->newInstanceArgs($partial);
                    $this->fail(
                        "{$fqcn} accepted construction without required field '{$drop}' — "
                        .'taxonomy contract violated.',
                    );
                } catch (ArgumentCountError) {
                    // Expected — gate held.
                }
            }
        }
    }

    public function test_base_event_to_payload_round_trips_all_declared_fields(): void
    {
        $namespace = $this->taxonomy['namespace'];

        foreach ($this->taxonomy['events'] as $event) {
            $fqcn = $namespace.'\\'.$event['name'];

            $args = [];
            foreach ($event['fields'] as $field) {
                if ($field['required']) {
                    $args[$field['name']] = $this->sampleValue($field['type']);
                }
            }

            /** @var BaseEvent $instance */
            $instance = (new ReflectionClass($fqcn))->newInstanceArgs($args);
            $payload = $instance->toPayload();

            foreach ($event['fields'] as $field) {
                $this->assertArrayHasKey(
                    $field['name'],
                    $payload,
                    "{$fqcn}::toPayload() missing key '{$field['name']}'",
                );
            }
        }
    }

    public function test_base_event_metadata_methods_are_implemented(): void
    {
        $namespace = $this->taxonomy['namespace'];
        $version = $this->taxonomy['version'];

        foreach ($this->taxonomy['events'] as $event) {
            $fqcn = $namespace.'\\'.$event['name'];

            $nameMethod = new ReflectionMethod($fqcn, 'name');
            $versionMethod = new ReflectionMethod($fqcn, 'taxonomyVersion');

            $this->assertTrue($nameMethod->isStatic());
            $this->assertTrue($versionMethod->isStatic());
            $this->assertSame($event['slug'], $nameMethod->invoke(null));
            $this->assertSame($version, $versionMethod->invoke(null));
        }
    }

    private function sampleValue(string $type): string|int|bool
    {
        return match ($type) {
            'string', 'iso8601' => 'sample',
            'int' => 1,
            'bool' => true,
            default => throw new \LogicException("Unknown taxonomy type: {$type}"),
        };
    }
}
