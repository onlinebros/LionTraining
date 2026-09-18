<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Partner\SpotMergeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fold one claimed position, and everything under it, into another account.
 *
 * The same operation as the button on the spots board, run from the command
 * line because the subtree can be large. Rewriting tens of thousands of ltree
 * paths inside an HTTP request means a proxy timeout can land in the middle of
 * the one operation in this system that rearranges a live genealogy — the
 * transaction would roll back, but nobody watching the browser would know that.
 *
 * `--dry-run` prints exactly what would move without touching anything, and is
 * the sensible first call every time.
 */
class MergePartnerSpot extends Command
{
    protected $signature = 'partners:merge
                            {spot : The position to retire — its partner id, email, or numeric id}
                            {into : The account that absorbs it — email or numeric id}
                            {--dry-run : Show what would move and change nothing}
                            {--force : Skip the confirmation}';

    protected $description = 'Merge a claimed partner position and its downline into another account';

    public function handle(SpotMergeService $merges): int
    {
        $spot = $this->resolve($this->argument('spot'));
        $into = $this->resolve($this->argument('into'));

        if ($spot === null || $into === null) {
            $this->error('Could not find ' . ($spot === null ? 'the position' : 'the target account') . '.');

            return self::FAILURE;
        }

        $this->describe($spot, $into);

        if (($reason = $merges->reasonItCannotMerge($spot, $into)) !== null) {
            $this->error($reason);

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing was changed.');

            return self::SUCCESS;
        }

        $below = $this->countBelow($spot);

        if (! $this->option('force') && ! $this->confirm(
            "Move {$below} position(s) under {$into->name} and retire {$spot->external_user_id}? There is no undo.",
            false,
        )) {
            $this->line('Nothing was changed.');

            return self::SUCCESS;
        }

        $this->line('Merging…');
        $started = microtime(true);

        try {
            $moved = $merges->merge($spot, $into);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('  %s position(s) moved in %.1fs.', number_format($moved), microtime(true) - $started));
        $this->line("  {$spot->external_user_id} is retired and out of the structure.");

        return self::SUCCESS;
    }

    /** By partner id, email, or our own id — whichever the operator has to hand. */
    private function resolve(string $reference): ?User
    {
        $reference = trim($reference);

        if (ctype_digit($reference)) {
            return User::find((int) $reference);
        }

        return User::where('external_user_id', $reference)->first()
            ?? User::whereRaw('lower(email) = ?', [strtolower($reference)])->first();
    }

    private function countBelow(User $spot): int
    {
        return (int) DB::scalar(
            'select count(*) from users where placement_path <@ ?::ltree and id <> ?',
            [$spot->placement_path, $spot->id],
        );
    }

    private function describe(User $spot, User $into): void
    {
        $below = $this->countBelow($spot);

        $unclaimed = (int) DB::scalar(
            "select count(*) from users where placement_path <@ ?::ltree and id <> ? and account_status = 'holding'",
            [$spot->placement_path, $spot->id],
        );

        $this->table(['', 'Retiring', 'Absorbing into'], [
            ['Account',  $spot->name . ' <' . ($spot->email ?: '—') . '>', $into->name . ' <' . ($into->email ?: '—') . '>'],
            ['Our id',   $spot->id, $into->id],
            ['Partner id', $spot->external_user_id ?: '—', $into->external_user_id ?: '—'],
            ['Path',     $spot->placement_path, $into->placement_path],
            ['Depth',    substr_count((string) $spot->placement_path, '.') + 1, substr_count((string) $into->placement_path, '.') + 1],
        ]);

        $this->line('  Positions below it: ' . number_format($below)
            . ' (' . number_format($unclaimed) . ' still unclaimed)');
        $this->line('  Direct children moving up: '
            . number_format(User::where('placement_parent_id', $spot->id)->count()));
        $this->newLine();
    }
}
