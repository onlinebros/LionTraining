<?php

namespace App\Http\ViewComposers;

use App\Services\Vendor\PromotionTracker;
use App\Support\Vendors;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The "buy yours" offer: partners buying a system for themselves while places in
 * the running promotion remain.
 *
 * Shared as `$buyYours` with the member dashboard, Product Sales, the promotion
 * page and the sidebar. It is null, and the offer disappears everywhere, when no
 * promotion is running, when the vendor is switched off, or once every place is
 * taken, so nobody is marketed a place that no longer exists.
 */
class BuyYoursPromoComposer
{
    public function __construct(private readonly PromotionTracker $promotions) {}

    public function compose(View $view): void
    {
        $view->with('buyYours', $this->offer());
    }

    /**
     * @return array{name:string, product_name:string, cap:int, filled:int, remaining:int, percent:int, buy_url:string, has_sponsor:bool}|null
     */
    private function offer(): ?array
    {
        $key = $this->promotions->currentKey();

        if ($key === null) {
            return null;
        }

        $promotion = (array) $this->promotions->find($key);
        $vendor    = (string) ($promotion['vendor'] ?? '');
        $product   = Vendors::product($vendor, (string) ($promotion['product'] ?? ''));

        if ($product === null || ! Vendors::enabled($vendor)) {
            return null;
        }

        /*
         * Cached for a minute. The sidebar renders this on every member page,
         * and "places left" a minute stale costs nothing, where re-reading the
         * standings on every page view would.
         */
        $places = Cache::remember("promotion:{$key}:places", 60, function () use ($key) {
            $standings = $this->promotions->standings($key);

            return ['cap' => $standings['cap'], 'filled' => $standings['filled'], 'remaining' => $standings['remaining']];
        });

        if ($places['remaining'] <= 0) {
            return null;
        }

        return $places + [
            'name'         => (string) ($promotion['name'] ?? ''),
            'product_name' => Str::plural((string) ($product['name'] ?? '')),
            'percent'      => (int) floor($places['filled'] / max(1, $places['cap']) * 100),
            'buy_url'      => route('member.sales.buy', [$vendor, (string) $promotion['product']]),
            'has_sponsor'  => auth()->user()?->sponsor_id !== null,
        ];
    }
}
