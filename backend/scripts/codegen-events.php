<?php

declare(strict_types=1);

/**
 * Event taxonomy codegen.
 *
 *   php scripts/codegen-events.php          # write generated classes to disk
 *   php scripts/codegen-events.php --check  # exit 1 if disk differs from generator output (CI gate)
 *
 * Reads config/events/taxonomy.v1.json and writes one final readonly class per
 * event into app/Events/Taxonomy/. Generated files carry a DO-NOT-EDIT header
 * and are byte-deterministic so the --check mode is a reliable drift gate.
 */
const TYPE_MAP = [
    'string' => 'string',
    'int' => 'int',
    'bool' => 'bool',
    'iso8601' => 'string',
];

$root = dirname(__DIR__);
$taxonomyPath = $root.'/config/events/taxonomy.v1.json';
$outputDir = $root.'/app/Events/Taxonomy';

$mode = 'write';
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--check') {
        $mode = 'check';
    } elseif ($arg === '--write') {
        $mode = 'write';
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(2);
    }
}

if (! is_file($taxonomyPath)) {
    fwrite(STDERR, "Taxonomy file not found at {$taxonomyPath}\n");
    exit(2);
}

$raw = file_get_contents($taxonomyPath);
if ($raw === false) {
    fwrite(STDERR, "Failed to read {$taxonomyPath}\n");
    exit(2);
}

try {
    /** @var array<string, mixed> $taxonomy */
    $taxonomy = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, "Taxonomy JSON parse error: {$e->getMessage()}\n");
    exit(2);
}

foreach (['version', 'namespace', 'events'] as $key) {
    if (! array_key_exists($key, $taxonomy)) {
        fwrite(STDERR, "Taxonomy missing top-level key '{$key}'\n");
        exit(2);
    }
}

$version = (string) $taxonomy['version'];
$namespace = (string) $taxonomy['namespace'];
$events = $taxonomy['events'];
if (! is_array($events)) {
    fwrite(STDERR, "Taxonomy 'events' must be an array\n");
    exit(2);
}

$generated = [];
foreach ($events as $event) {
    if (! is_array($event)) {
        fwrite(STDERR, "Each event entry must be an object\n");
        exit(2);
    }

    $name = (string) ($event['name'] ?? '');
    $slug = (string) ($event['slug'] ?? '');
    $description = (string) ($event['description'] ?? '');
    $fields = $event['fields'] ?? [];

    if ($name === '' || ! preg_match('/^[A-Z][A-Za-z0-9]+$/', $name)) {
        fwrite(STDERR, "Invalid event name: '{$name}' (must be StudlyCase)\n");
        exit(2);
    }
    if ($slug === '' || ! preg_match('/^[a-z][a-z0-9_]+$/', $slug)) {
        fwrite(STDERR, "Invalid event slug for {$name}: '{$slug}' (must be snake_case)\n");
        exit(2);
    }
    if (! is_array($fields) || $fields === []) {
        fwrite(STDERR, "Event {$name} has no fields — every event must declare at least one\n");
        exit(2);
    }

    $generated[$name] = renderEventClass(
        namespace: $namespace,
        version: $version,
        name: $name,
        slug: $slug,
        description: $description,
        fields: array_values($fields),
    );
}

ksort($generated);

if (! is_dir($outputDir) && ! mkdir($outputDir, 0775, true) && ! is_dir($outputDir)) {
    fwrite(STDERR, "Failed to create output directory {$outputDir}\n");
    exit(2);
}

$existing = [];
foreach (glob($outputDir.'/*.php') ?: [] as $path) {
    $basename = basename($path, '.php');
    if ($basename === 'BaseEvent') {
        continue;
    }
    $existing[$basename] = (string) file_get_contents($path);
}

$drift = false;
foreach ($generated as $className => $contents) {
    $path = $outputDir.'/'.$className.'.php';
    $current = $existing[$className] ?? null;
    unset($existing[$className]);

    if ($current === $contents) {
        continue;
    }

    $drift = true;
    if ($mode === 'check') {
        fwrite(STDERR, "DRIFT: {$path} is out of date — run `php scripts/codegen-events.php`\n");
    } else {
        if (file_put_contents($path, $contents) === false) {
            fwrite(STDERR, "Failed to write {$path}\n");
            exit(2);
        }
        fwrite(STDOUT, "wrote {$path}\n");
    }
}

foreach ($existing as $className => $_unused) {
    $path = $outputDir.'/'.$className.'.php';
    $drift = true;
    if ($mode === 'check') {
        fwrite(STDERR, "DRIFT: {$path} exists but is not in the taxonomy — run `php scripts/codegen-events.php`\n");
    } else {
        unlink($path);
        fwrite(STDOUT, "removed stale {$path}\n");
    }
}

if ($mode === 'check') {
    if ($drift) {
        fwrite(STDERR, "Event taxonomy codegen drift detected.\n");
        exit(1);
    }
    fwrite(STDOUT, "Event taxonomy: no drift.\n");
}

exit(0);

/**
 * @param  array<int, array<string, mixed>>  $fields
 */
function renderEventClass(
    string $namespace,
    string $version,
    string $name,
    string $slug,
    string $description,
    array $fields,
): string {
    // Parameters: required (no default) come first, optional (?type = null) after.
    // Within each group keep the taxonomy author's order — that makes diffs of
    // the taxonomy map cleanly onto diffs of the generated file.
    $required = [];
    $optional = [];
    foreach ($fields as $field) {
        $fName = (string) ($field['name'] ?? '');
        $fType = (string) ($field['type'] ?? '');
        $fRequired = (bool) ($field['required'] ?? false);
        $fDoc = isset($field['doc']) ? (string) $field['doc'] : '';

        if ($fName === '' || ! preg_match('/^[a-z][a-z0-9_]*$/', $fName)) {
            fwrite(STDERR, "Invalid field name '{$fName}' on event {$name} (must be snake_case)\n");
            exit(2);
        }
        if (! array_key_exists($fType, TYPE_MAP)) {
            fwrite(STDERR, "Unknown field type '{$fType}' on {$name}.{$fName}\n");
            exit(2);
        }

        if ($fRequired) {
            $required[] = [$fName, $fType, $fDoc];
        } else {
            $optional[] = [$fName, $fType, $fDoc];
        }
    }

    $paramLines = [];
    foreach ($required as [$fName, $fType, $fDoc]) {
        if ($fDoc !== '') {
            $paramLines[] = '        // '.$fDoc;
        }
        $paramLines[] = '        public '.TYPE_MAP[$fType].' $'.$fName.',';
    }
    foreach ($optional as [$fName, $fType, $fDoc]) {
        if ($fDoc !== '') {
            $paramLines[] = '        // '.$fDoc;
        }
        $paramLines[] = '        public ?'.TYPE_MAP[$fType].' $'.$fName.' = null,';
    }

    $params = implode("\n", $paramLines);

    $descriptionDoc = $description !== ''
        ? " * {$description}\n *\n"
        : '';

    return <<<PHP
<?php

declare(strict_types=1);

// AUTOGENERATED FROM config/events/taxonomy.v1.json @ version {$version}
// DO NOT EDIT — run `php scripts/codegen-events.php` after updating the taxonomy.

namespace {$namespace};

/**
{$descriptionDoc} * Generated event DTO. Construct with named arguments; PHPStan will flag any
 * call site that omits a required field as an argument-count error.
 */
final readonly class {$name} extends BaseEvent
{
    public function __construct(
{$params}
    ) {}

    public static function name(): string
    {
        return '{$slug}';
    }

    public static function taxonomyVersion(): string
    {
        return '{$version}';
    }
}

PHP;
}
