<?php

namespace App\Console\Commands;

use App\Services\Stripe\StripeClientFactory;
use Illuminate\Console\Command;
use Stripe\Exception\ApiErrorException;

/**
 * Creates the subscription product and price at the provider from config.
 *
 * A new environment is set up by running a command rather than by clicking
 * through a dashboard, which means staging and production can be provably
 * identical. Idempotent: it reuses anything already configured and prints the
 * ids to paste into .env.
 */
class BillingBootstrapProduct extends Command
{
    protected $signature = 'billing:bootstrap-product {--force : Create a new price even if one is configured}';

    protected $description = 'Create the subscription product and price at the payment provider';

    public function handle(StripeClientFactory $factory): int
    {
        if (! $factory->isConfigured()) {
            $this->error('STRIPE_KEY / STRIPE_SECRET are not set.');

            return self::FAILURE;
        }

        $client   = $factory->client();
        $name     = (string) config('stripe.subscription.name');
        $amount   = (int) config('stripe.subscription.amount');
        $currency = (string) config('stripe.subscription.currency');
        $interval = (string) config('stripe.subscription.interval');

        if ($amount <= 0) {
            $this->error('STRIPE_PRICE_AMOUNT must be a positive integer in minor units (e.g. 19900 = $199.00).');

            return self::FAILURE;
        }

        $this->line('');
        $this->info($factory->isTestMode() ? 'Provider: TEST mode' : 'Provider: LIVE mode');

        try {
            // ── Product ───────────────────────────────────────────────────────
            $productId = config('stripe.subscription.product_id');

            if (filled($productId) && ! $this->option('force')) {
                $product = $client->products->retrieve($productId);
                $this->line("  Reusing product  {$product->id} ({$product->name})");
            } else {
                $product = $client->products->create([
                    'name'     => $name,
                    'metadata' => ['app' => 'quantumlife'],
                ]);
                $this->line("  Created product  {$product->id} ({$product->name})");
            }

            // ── Price ─────────────────────────────────────────────────────────
            $priceId = config('stripe.subscription.price_id');

            if (filled($priceId) && ! $this->option('force')) {
                $price = $client->prices->retrieve($priceId);
                $this->line("  Reusing price    {$price->id}");
            } else {
                // Prices are immutable at the provider — changing the amount
                // means creating a new price, not editing this one. Existing
                // subscriptions stay on the old price until migrated deliberately.
                $price = $client->prices->create([
                    'product'     => $product->id,
                    'unit_amount' => $amount,
                    'currency'    => $currency,
                    'recurring'   => ['interval' => $interval],
                    'metadata'    => ['app' => 'quantumlife'],
                ]);
                $this->line("  Created price    {$price->id}");
            }
        } catch (ApiErrorException $e) {
            $this->error('Provider error: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $this->info('Put these in .env:');
        $this->line('');
        $this->line("STRIPE_PRODUCT_ID={$product->id}");
        $this->line("STRIPE_PRICE_ID={$price->id}");
        $this->line('');
        $this->comment('Then run: php artisan billing:preflight');
        $this->line('');

        return self::SUCCESS;
    }
}
