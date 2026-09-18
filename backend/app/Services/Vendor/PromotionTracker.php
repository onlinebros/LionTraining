<?php

namespace App\Services\Vendor;

use App\Models\VendorLead;
use App\Support\Vendors;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Standings for a capped sales promotion, such as the first 100 PlasmaGuard PRO
 * systems sold.
 *
 * Worked out from confirmed orders on every read instead of stored as claimed
 * places. A refund inside the lock window gives its place back by itself and
 * the next confirmed sale moves up, with nothing to keep in step. After the
 * lock window a place is final: a refund no longer moves anyone.
 */
class PromotionTracker
{
    /** @return array<string,mixed>|null */
    public function find(string $key): ?array
    {
        $promotion = config("promotions.promotions.{$key}");

        return is_array($promotion) ? $promotion : null;
    }

    /** The promotion partners see: the first one enabled and not yet ended. */
    public function currentKey(): ?string
    {
        foreach ((array) config('promotions.promotions', []) as $key => $promotion) {
            if (! ($promotion['enabled'] ?? false)) {
                continue;
            }

            $ends = $this->moment($promotion, 'ends_at');

            if ($ends !== null && $ends->isPast()) {
                continue;
            }

            return (string) $key;
        }

        return null;
    }

    /** @param  array<string,mixed>  $promotion */
    public function cap(array $promotion): int
    {
        return max(1, (int) ($promotion['cap'] ?? 100));
    }

    /**
     * Days after a sale during which a refund still moves the places.
     *
     * @param  array<string,mixed>  $promotion
     */
    public function lockDays(array $promotion): int
    {
        return (int) ($promotion['bonus']['lock_days'] ?? Vendors::clawbackDays((string) ($promotion['vendor'] ?? '')));
    }

    /**
     * The promotion's sales, one slot per counted system, in confirmation order.
     *
     * Counted: confirmed orders, plus orders refunded after their lock window
     * (their places are final). Never more than $limit slots.
     *
     * @return list<array{lead: VendorLead, unit: int}>
     */
    public function rankedUnits(string $key, int $limit): array
    {
        $promotion = $this->find($key) ?? throw new InvalidArgumentException("Unknown promotion: {$key}");

        if ($limit < 1) {
            return [];
        }

        $byUnits  = ($promotion['counts'] ?? 'units') !== 'orders';
        $starts   = $this->moment($promotion, 'starts_at');
        $ends     = $this->moment($promotion, 'ends_at');
        $lockDays = $this->lockDays($promotion);

        // Each order fills at least one slot, so $limit orders is always enough.
        $orders = VendorLead::with(['member:id,name', 'creditedMember:id,name'])
            ->forVendor((string) ($promotion['vendor'] ?? ''))
            ->where('product_key', (string) ($promotion['product'] ?? ''))
            ->whereNotNull('converted_at')
            ->where(function ($query) use ($lockDays) {
                $query->where('status', VendorLead::STATUS_CONVERTED)
                    ->orWhere(function ($refunded) use ($lockDays) {
                        $refunded->where('status', VendorLead::STATUS_REFUNDED)
                            ->whereNotNull('refunded_at')
                            ->whereRaw('refunded_at >= converted_at + make_interval(days => CAST(? AS integer))', [$lockDays]);
                    });
            })
            ->when($starts !== null, fn ($query) => $query->where('converted_at', '>=', $starts))
            ->when($ends !== null, fn ($query) => $query->where('converted_at', '<', $ends))
            ->orderBy('converted_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $slots = [];

        foreach ($orders as $lead) {
            $units = $byUnits ? max(1, (int) $lead->quantity) : 1;

            for ($unit = 1; $unit <= $units && count($slots) < $limit; $unit++) {
                $slots[] = ['lead' => $lead, 'unit' => $unit];
            }

            if (count($slots) >= $limit) {
                break;
            }
        }

        return $slots;
    }

    /**
     * @return array{
     *     key: string, promotion: array<string,mixed>, vendor_name: string,
     *     cap: int, counts: string, filled: int, remaining: int, full: bool,
     *     starts_label: ?string,
     *     entries: list<array{from: int, to: int, units: int, partial: bool, lead: VendorLead, credited_id: ?int, credited_name: string}>,
     *     leaderboard: list<array{user_id: int, name: string, units: int, orders: int, first_place: int}>,
     *     terms: ?array<string,mixed>,
     *     pool: ?array{units: int, filled: int, remaining: int, percent: int}
     * }
     */
    public function standings(string $key): array
    {
        $promotion = $this->find($key) ?? throw new InvalidArgumentException("Unknown promotion: {$key}");

        $cap     = $this->cap($promotion);
        $byUnits = ($promotion['counts'] ?? 'units') !== 'orders';
        $terms   = PromotionBonuses::terms($promotion);
        $slots   = $this->rankedUnits($key, $cap + ($terms['pool_units'] ?? 0));

        $entries = [];
        $board   = [];

        foreach (array_slice($slots, 0, $cap) as $index => $slot) {
            $lead  = $slot['lead'];
            $place = $index + 1;
            $last  = array_key_last($entries);

            // Consecutive slots of one order are one entry: "#3–5".
            if ($last !== null && $entries[$last]['lead']->id === $lead->id) {
                $entries[$last]['to']      = $place;
                $entries[$last]['units']++;
                $entries[$last]['partial'] = $entries[$last]['units'] < ($byUnits ? max(1, (int) $lead->quantity) : 1);
            } else {
                $entries[] = [
                    'from'          => $place,
                    'to'            => $place,
                    'units'         => 1,
                    'partial'       => $byUnits && (int) $lead->quantity > 1,
                    'lead'          => $lead,
                    'credited_id'   => $lead->credited_member_id,
                    'credited_name' => $lead->creditedMember->name ?? 'Former partner',
                ];
            }

            $creditedId = $lead->credited_member_id;

            if ($creditedId !== null) {
                $board[$creditedId] ??= [
                    'user_id'     => $creditedId,
                    'name'        => $lead->creditedMember->name ?? 'Former partner',
                    'units'       => 0,
                    'orders'      => 0,
                    'first_place' => $place,
                    'last_lead'   => null,
                ];
                $board[$creditedId]['units']++;

                if ($board[$creditedId]['last_lead'] !== $lead->id) {
                    $board[$creditedId]['orders']++;
                    $board[$creditedId]['last_lead'] = $lead->id;
                }
            }
        }

        // Most places first; a tie goes to whoever sold first.
        $leaderboard = array_map(static function (array $row): array {
            unset($row['last_lead']);

            return $row;
        }, array_values($board));
        usort($leaderboard, fn (array $a, array $b) => [$b['units'], $a['first_place']] <=> [$a['units'], $b['first_place']]);

        $filled   = min($cap, count($slots));
        $timezone = (string) ($promotion['timezone'] ?? config('app.timezone'));

        $pool = null;

        if ($terms !== null && $terms['pool_units'] > 0) {
            $poolFilled = max(0, count($slots) - $cap);
            $pool = [
                'units'     => $terms['pool_units'],
                'filled'    => $poolFilled,
                'remaining' => $terms['pool_units'] - $poolFilled,
                'percent'   => (int) floor($poolFilled / $terms['pool_units'] * 100),
            ];
        }

        return [
            'key'          => $key,
            'promotion'    => $promotion,
            'vendor_name'  => Vendors::name((string) ($promotion['vendor'] ?? '')),
            'cap'          => $cap,
            'counts'       => $byUnits ? 'units' : 'orders',
            'filled'       => $filled,
            'remaining'    => $cap - $filled,
            'full'         => $filled >= $cap,
            'starts_label' => $this->moment($promotion, 'starts_at')?->copy()->setTimezone($timezone)->format('j M Y'),
            'entries'      => $entries,
            'leaderboard'  => $leaderboard,
            'terms'        => $terms,
            'pool'         => $pool,
        ];
    }

    /**
     * A promotion boundary, converted into the app's timezone.
     *
     * Written in the promotion's own timezone but compared in the app's: the
     * query builder formats a Carbon in whatever zone it carries, so one left in
     * Eastern would be read as UTC and move the window by hours.
     *
     * @param  array<string,mixed>  $promotion
     */
    private function moment(array $promotion, string $field): ?Carbon
    {
        $value = $promotion[$field] ?? null;

        if (blank($value)) {
            return null;
        }

        return Carbon::parse((string) $value, (string) ($promotion['timezone'] ?? config('app.timezone')))
            ->setTimezone((string) config('app.timezone'));
    }
}
