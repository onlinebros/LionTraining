<?php

declare(strict_types=1);

namespace App\Events\Taxonomy;

/**
 * Hand-written base for taxonomy-generated event DTOs. Subclasses are codegen'd
 * from config/events/taxonomy.v1.json — see scripts/codegen-events.php. The
 * class itself is intentionally tiny: the value of the taxonomy lives in the
 * subclasses' constructor signatures, which PHPStan enforces at call sites.
 */
abstract readonly class BaseEvent
{
    /**
     * Wire-format event name (matches the taxonomy "slug").
     */
    abstract public static function name(): string;

    /**
     * Taxonomy version this class was generated against.
     */
    abstract public static function taxonomyVersion(): string;

    /**
     * Public, declared fields as an associative payload. Used by the dispatcher
     * (analytics sink, queue payload, audit log). Subclasses are final readonly
     * classes whose only declared public properties are taxonomy fields, so a
     * generic get_object_vars() is safe.
     *
     * @return array<string, scalar|null>
     */
    public function toPayload(): array
    {
        /** @var array<string, scalar|null> $vars */
        $vars = get_object_vars($this);

        return $vars;
    }
}
