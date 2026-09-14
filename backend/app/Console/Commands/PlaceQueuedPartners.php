<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Genealogy\GenealogyService;
use Illuminate\Console\Command;

/**
 * Places everyone still sitting in the placement queue.
 *
 * Registration places synchronously, so in normal operation this finds nothing.
 * It is the safety net for the cases that matter: a registration whose
 * placement transaction failed, accounts created by an admin or an importer
 * rather than the signup form, and anyone queued while the structure was
 * misconfigured.
 *
 * Schedule it every few minutes during the pre-launch campaign.
 */
class PlaceQueuedPartners extends Command
{
    protected $signature = 'network:place-queued
                            {--dry-run : List who would be placed without placing them}
                            {--limit= : Place at most this many}';

    protected $description = 'Place partners waiting in the placement queue';

    public function handle(GenealogyService $genealogy): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        if ($this->option('dry-run')) {
            $query = User::query()
                ->where('placement_status', User::PLACEMENT_QUEUED)
                ->orderBy('placement_queued_at')
                ->orderBy('id');

            if ($limit !== null) {
                $query->limit($limit);
            }

            $queued = $query->get(['id', 'name', 'email', 'sponsor_id', 'placement_queued_at']);

            if ($queued->isEmpty()) {
                $this->info('Nobody is queued.');

                return self::SUCCESS;
            }

            $this->table(
                ['ID', 'Name', 'Email', 'Sponsor', 'Queued at'],
                $queued->map(fn (User $u) => [
                    $u->id,
                    $u->name,
                    $u->email,
                    $u->sponsor_id ?? '— (root)',
                    $u->placement_queued_at?->toDateTimeString() ?? '—',
                ])->all(),
            );

            $this->comment("{$queued->count()} would be placed.");

            return self::SUCCESS;
        }

        $placed = $genealogy->placeQueued($limit);

        $this->info($placed === 0 ? 'Nobody was queued.' : "Placed {$placed} partner(s).");

        return self::SUCCESS;
    }
}
