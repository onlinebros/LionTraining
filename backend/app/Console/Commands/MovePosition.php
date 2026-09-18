<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Genealogy\PositionMover;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Move a position, and its whole branch, beneath a different account.
 *
 * The deliberate exception to "placements are permanent" — see PositionMover
 * for why it exists and how narrow it is meant to stay. Deliberately a command
 * with no screen behind it: this should be something somebody chose to do, not
 * something a button invites.
 */
class MovePosition extends Command
{
    protected $signature = 'partners:move
                            {position : The account to move — email, partner id, or numeric id}
                            {under : The account it should sit beneath — email or numeric id}
                            {--dry-run : Show what would move and change nothing}
                            {--force : Skip the confirmation}';

    protected $description = 'Move a position and everything under it beneath another account';

    public function handle(PositionMover $mover): int
    {
        $position  = $this->resolve($this->argument('position'));
        $newParent = $this->resolve($this->argument('under'));

        if ($position === null || $newParent === null) {
            $this->error('Could not find ' . ($position === null ? 'the position' : 'the new parent') . '.');

            return self::FAILURE;
        }

        $below = $this->countBelow($position);

        $this->table(['', 'Moving', 'To sit beneath'], [
            ['Account', $position->name . ' <' . ($position->email ?: '—') . '>',
                        $newParent->name . ' <' . ($newParent->email ?: '—') . '>'],
            ['Our id',  $position->id, $newParent->id],
            ['Partner id', $position->external_user_id ?: '—', $newParent->external_user_id ?: '—'],
            ['Path now', $position->placement_path, $newParent->placement_path],
            ['Path after', $newParent->placement_path . '.' . $position->id, '—'],
        ]);

        $this->line('  Positions travelling with it: ' . number_format($below));
        $this->line('  Its sponsor becomes: ' . $newParent->name);
        $this->newLine();

        if (($reason = $mover->reasonItCannotMove($position, $newParent)) !== null) {
            $this->error($reason);

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(
            "Move {$position->name} and " . number_format($below) . ' position(s) beneath '
            . "{$newParent->name}? There is no undo.",
            false,
        )) {
            $this->line('Nothing was changed.');

            return self::SUCCESS;
        }

        $this->line('Moving…');
        $started = microtime(true);

        try {
            $moved = $mover->move($position, $newParent);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('  %s row(s) rewritten in %.1fs.', number_format($moved), microtime(true) - $started));
        $this->line("  {$position->name} now sits beneath {$newParent->name}.");

        return self::SUCCESS;
    }

    private function resolve(string $reference): ?User
    {
        $reference = trim($reference);

        if (ctype_digit($reference)) {
            return User::find((int) $reference);
        }

        return User::whereRaw('lower(email) = ?', [strtolower($reference)])->first()
            ?? User::where('external_user_id', $reference)->first();
    }

    private function countBelow(User $position): int
    {
        if ($position->placement_path === null) {
            return 0;
        }

        return (int) DB::scalar(
            'select count(*) from users where placement_path <@ ?::ltree and id <> ?',
            [$position->placement_path, $position->id],
        );
    }
}
