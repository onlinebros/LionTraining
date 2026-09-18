<?php

namespace App\Console\Commands;

use App\Services\Vendor\PromotionBonuses;
use Illuminate\Console\Command;

/**
 * Bring launch-special credits in line with the current standings.
 *
 * Normally run automatically whenever a sale is confirmed, refunded or
 * reassigned. This is for after a deploy, or after fixing an order by hand.
 */
class ReconcilePromotionBonuses extends Command
{
    protected $signature = 'promotions:reconcile-bonuses {promotion? : A key from config/promotions.php; all bonus promotions if omitted}';

    protected $description = 'Credit or void launch-special bonuses to match the current standings';

    public function handle(PromotionBonuses $bonuses): int
    {
        $keys = $this->argument('promotion')
            ? [(string) $this->argument('promotion')]
            : array_keys(array_filter(
                (array) config('promotions.promotions', []),
                fn ($promotion) => PromotionBonuses::terms($promotion) !== null,
            ));

        foreach ($keys as $key) {
            $bonuses->reconcile((string) $key);

            $overview = $bonuses->overview((string) $key);
            $this->info(sprintf(
                '%s: $%s in place bonuses (%d), $%s in pool shares, %d needing a clawback',
                $key,
                number_format($overview['place_total'], 2),
                $overview['place_count'],
                number_format($overview['pool_total'], 2),
                $overview['needs_clawback']->count(),
            ));
        }

        return self::SUCCESS;
    }
}
