<?php

namespace App\Console\Commands;

use App\Models\PartnerCompany;
use Illuminate\Console\Command;

/**
 * Rebuild the claimed/unclaimed counters from the positions themselves.
 *
 * The counters on `partner_companies` are maintained rather than computed,
 * because computing them means reading every position a company has — fifteen
 * seconds for iHub, on a page somebody is waiting on. Maintained counters can
 * drift: a row edited by hand, a failed decrement, an import restored from a
 * backup.
 *
 * This is the answer whenever a number looks wrong, and the thing to run after
 * anything unusual happens to the data. It is deliberately a command and not a
 * schedule: it is the expensive query, and it should run when somebody means it.
 */
class RecountPartnerSpots extends Command
{
    protected $signature = 'partners:recount {company? : Slug of one company, or all of them}';

    protected $description = 'Rebuild the claimed/unclaimed counters on partner companies';

    public function handle(): int
    {
        $companies = PartnerCompany::query()
            ->when($this->argument('company'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('name')
            ->get();

        if ($companies->isEmpty()) {
            $this->error('No partner companies matched.');

            return self::FAILURE;
        }

        $rows = [];

        foreach ($companies as $company) {
            $before = $company->spotCounts();

            $this->line("Counting {$company->name}… (this reads every position it has)");
            $started = microtime(true);
            $after   = $company->recount();

            $rows[] = [
                $company->name,
                number_format($after['total']),
                number_format($after['unclaimed']),
                number_format($after['claimed']),
                $before === $after ? '—' : 'corrected',
                sprintf('%.1fs', microtime(true) - $started),
            ];
        }

        $this->newLine();
        $this->table(['Company', 'Positions', 'Unclaimed', 'Claimed', 'Counters', 'Took'], $rows);

        return self::SUCCESS;
    }
}
