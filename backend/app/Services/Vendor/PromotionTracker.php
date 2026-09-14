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
 * places. A refund gives its place back by itself and the next confirmed sale
 * moves up, with nothing to keep in step. It never reads more than `cap`
 * orders, since every order fills at least one place.
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

    /**
     * @return array{
     *     key: string, promotion: array<string,mixed>, vendor_name: string,
     *     cap: int, counts: string, filled: int, remaining: int, full: bool,
     *     starts_label: ?string,
     *     entries: list<array{from: int, to: int, units: int, partial: bool, lead: VendorLead, credited_id: ?int, credited_name: string}>,
     *     leaderboard: list<array{user_id: int, name: string, units: int, orders: int, first_place: int}>
     * }
     */
    public function standings(string $key): array
    {
        $promotion = $this->find($key) ?? throw new InvalidArgumentException("Unknown promotion: {$key}");

        $cap     = max(1, (int) ($promotion['cap'] ?? 100));
        $byUnits = ($promotion['counts'] ?? 'units') !== 'orders';
        $starts  = $this->moment($promotion, 'starts_at');
        $ends    = $this->moment($promotion, 'ends_at');

        $orders = VendorLead::with(['member:id,name', 'creditedMember:id,name'])
            ->forVendor((string) ($promotion['vendor'] ?? ''))
            ->where('product_key', (string) ($promotion['product'] ?? ''))
            ->converted()
            ->when($starts !== null, fn ($query) => $query->where('converted_at', '>=', $starts))
            ->when($ends !== null, fn ($query) => $query->where('converted_at', '<', $ends))
            ->orderBy('converted_at')
            ->orderBy('id')
            ->limit($cap)
            ->get();

        $filled  = 0;
        $entries = [];
        $board   = [];

        foreach ($orders as $lead) {
            if ($filled >= $cap) {
                break;
            }

            // The order that fills the last place counts only the places left.
            $wanted = $byUnits ? max(1, (int) $lead->quantity) : 1;
            $units  = min($wanted, $cap - $filled);

            $creditedId   = $lead->credited_member_id;
            $creditedName = $lead->creditedMember->name ?? 'Former partner';

            $entries[] = [
                'from'          => $filled + 1,
                'to'            => $filled + $units,
                'units'         => $units,
                'partial'       => $units < $wanted,
                'lead'          => $lead,
                'credited_id'   => $creditedId,
                'credited_name' => $creditedName,
            ];

            if ($creditedId !== null) {
                $board[$creditedId] ??= [
                    'user_id'     => $creditedId,
                    'name'        => $creditedName,
                    'units'       => 0,
                    'orders'      => 0,
                    'first_place' => $filled + 1,
                ];
                $board[$creditedId]['units'] += $units;
                $board[$creditedId]['orders']++;
            }

            $filled += $units;
        }

        // Most places first; a tie goes to whoever sold first.
        $leaderboard = array_values($board);
        usort($leaderboard, fn (array $a, array $b) => [$b['units'], $a['first_place']] <=> [$a['units'], $b['first_place']]);

        $timezone = (string) ($promotion['timezone'] ?? config('app.timezone'));

        return [
            'key'          => $key,
            'promotion'    => $promotion,
            'vendor_name'  => Vendors::name((string) ($promotion['vendor'] ?? '')),
            'cap'          => $cap,
            'counts'       => $byUnits ? 'units' : 'orders',
            'filled'       => $filled,
            'remaining'    => $cap - $filled,
            'full'         => $filled >= $cap,
            'starts_label' => $starts?->copy()->setTimezone($timezone)->format('j M Y'),
            'entries'      => $entries,
            'leaderboard'  => $leaderboard,
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
