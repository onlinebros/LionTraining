<?php

/*
 * PreToolUse hook for the Request Assistant's Claude CLI.
 *
 *   php request-assistant-guard.php /absolute/path/to/workspace
 *
 * The CLI hands every tool call to this script on stdin before running it.
 * Exit 0 lets the call through; exit 2 blocks it and the CLI shows Claude the
 * message on stderr. It fails closed: a call this script cannot read, a tool it
 * does not know, or a path it cannot prove is inside the workspace is refused.
 *
 * This is the boundary that keeps the assistant inside the project snapshot.
 * The CLI flags (--tools, dontAsk) are a second layer, not a replacement.
 */

const ALLOWED_TOOLS = ['Read', 'Grep', 'Glob'];

// Keys whose value is a filesystem path, on any of the allowed tools.
const PATH_KEYS = ['file_path', 'path', 'notebook_path'];

// Keys whose value is a glob, resolved against a path the tool already has.
const GLOB_KEYS = ['pattern', 'glob'];

function deny(string $reason): never
{
    fwrite(STDERR, "Blocked: {$reason} The Request Assistant can only read files inside the project.\n");
    exit(2);
}

/** Resolve "." and ".." without touching the disk (the path may not exist). */
function normalize(string $path): string
{
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($parts);

            continue;
        }
        $parts[] = $part;
    }

    return '/'.implode('/', $parts);
}

function inside(string $path, string $root): bool
{
    return $path === $root || str_starts_with($path, $root.'/');
}

$root = $argv[1] ?? '';
$root = $root !== '' ? realpath($root) : false;
if ($root === false || $root === '/') {
    deny('the workspace is not configured.');
}

$input = json_decode((string) stream_get_contents(STDIN), true);
if (! is_array($input)) {
    deny('the tool call could not be read.');
}

$tool = $input['tool_name'] ?? '';
$args = $input['tool_input'] ?? [];
if (! in_array($tool, ALLOWED_TOOLS, true)) {
    deny("the {$tool} tool is not available.");
}
if (! is_array($args)) {
    deny('the tool call could not be read.');
}

foreach (PATH_KEYS as $key) {
    if (! array_key_exists($key, $args) || $args[$key] === null || $args[$key] === '') {
        continue;
    }
    if (! is_string($args[$key])) {
        deny("{$key} is not a path.");
    }

    $raw = $args[$key];
    if (str_starts_with($raw, '~')) {
        deny('home-directory paths are outside the project.');
    }

    $path = normalize(str_starts_with($raw, '/') ? $raw : $root.'/'.$raw);
    if (! inside($path, $root)) {
        deny("{$raw} is outside the project.");
    }

    // The lexical check passed; a symlink could still point out. The workspace
    // is built without symlinks, so this is belt and braces.
    $real = realpath($path);
    if ($real !== false && ! inside($real, $root)) {
        deny("{$raw} is outside the project.");
    }

    if (preg_match('/(^|\/)\.env(\.|$)/', $path) && ! str_ends_with($path, '.env.example')) {
        deny('environment files are never readable.');
    }
}

foreach (GLOB_KEYS as $key) {
    // Grep's "pattern" is a regex over file contents, not a path.
    if ($tool === 'Grep' && $key === 'pattern') {
        continue;
    }
    if (! isset($args[$key])) {
        continue;
    }
    if (! is_string($args[$key])) {
        deny("{$key} is not a pattern.");
    }

    $glob = $args[$key];
    if (str_starts_with($glob, '/') || str_starts_with($glob, '~') || preg_match('/(^|[\/{,])\.\.([\/},]|$)/', $glob)) {
        deny("the pattern {$glob} reaches outside the project.");
    }
}

exit(0);
