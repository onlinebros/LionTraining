<?php

namespace App\Services\RequestAssistant;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The copy of the project the Request Assistant reads, and the only place it
 * can read.
 *
 * Built from the committed tree with `git archive`, so nothing that is not in
 * git — .env, storage/, vendor/, node_modules/, uploads — can be in it, and the
 * configured exclusions are never extracted at all. Symlinks are removed so no
 * path inside can lead outside. Rebuilt when HEAD moves; the path stays the
 * same, because the CLI files its conversations under the directory it ran in.
 */
class Workspace
{
    public function path(): string
    {
        return rtrim((string) config('request-assistant.workspace'), '/');
    }

    /** Make the copy match HEAD, and return the commit it holds. */
    public function refresh(): string
    {
        $repository = (string) config('request-assistant.repository');
        $head = trim($this->git($repository, ['rev-parse', 'HEAD']));
        if (! preg_match('/^[0-9a-f]{40}$/', $head)) {
            throw new RuntimeException('Could not read the repository HEAD.');
        }

        if ($this->currentCommit() === $head && is_dir($this->path())) {
            return $head;
        }

        $path = $this->path();
        $building = $path.'.building';
        $old = $path.'.old';
        File::deleteDirectory($building);
        File::ensureDirectoryExists($building, 0750);

        $archive = array_merge(
            ['git', '-C', $repository, 'archive', '--format=tar', $head, '--', '.'],
            $this->exclusions(),
        );
        $command = implode(' ', array_map('escapeshellarg', $archive))
            .' | tar -x -C '.escapeshellarg($building);

        $result = Process::timeout(300)->run(['bash', '-o', 'pipefail', '-c', $command]);
        if ($result->failed()) {
            File::deleteDirectory($building);
            throw new RuntimeException('Building the project copy failed: '.trim($result->errorOutput()));
        }

        Process::run(['find', $building, '-type', 'l', '-delete'])->throw();

        File::deleteDirectory($old);
        if (is_dir($path)) {
            rename($path, $old);
        }
        rename($building, $path);
        File::deleteDirectory($old);

        // Beside the copy, not in it: the assistant has no need to read it.
        file_put_contents($this->markerPath(), $head);

        return $head;
    }

    public function currentCommit(): ?string
    {
        $marker = $this->markerPath();

        return is_file($marker) ? trim((string) file_get_contents($marker)) : null;
    }

    /** @return list<string> git pathspecs that keep excluded files out of the archive */
    public function exclusions(): array
    {
        $specs = [];
        foreach ((array) config('request-assistant.exclude') as $path) {
            $specs[] = ':(exclude)'.rtrim((string) $path, '/');
        }
        foreach ((array) config('request-assistant.exclude_extensions') as $extension) {
            $specs[] = ':(exclude,glob,icase)**/*.'.$extension;
        }

        return $specs;
    }

    private function markerPath(): string
    {
        return $this->path().'.commit';
    }

    /** @param list<string> $args */
    private function git(string $repository, array $args): string
    {
        return Process::run(array_merge(['git', '-C', $repository], $args))->throw()->output();
    }
}
