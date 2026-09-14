<?php

namespace App\Console\Commands;

use App\Services\Stripe\StripeClientFactory;
use Illuminate\Console\Command;
use Stripe\Exception\ApiErrorException;

/**
 * Checks everything that has to be true before billing is switched on.
 *
 * Run as the last step before opening billing. It fails loudly rather than
 * warning quietly, because every one of these misconfigurations is silent in
 * normal operation and only shows up as lost money.
 */
class BillingPreflight extends Command
{
    protected $signature = 'billing:preflight {--url= : Public base URL webhooks should point at}';

    protected $description = 'Verify payment keys, price, and webhook registration before going live';

    public function handle(StripeClientFactory $factory): int
    {
        $failures = 0;
        $warnings = 0;

        $this->line('');
        $this->info('Billing preflight');
        $this->line(str_repeat('─', 60));

        // ── Keys ──────────────────────────────────────────────────────────────
        if (! $factory->isConfigured()) {
            $this->fail_('API keys', 'STRIPE_KEY and/or STRIPE_SECRET are not set.');
            $failures++;

            // Nothing below can run without a client.
            $this->summary($failures, $warnings);

            return self::FAILURE;
        }

        $mode = $factory->isTestMode() ? 'TEST' : 'LIVE';
        $this->ok('API keys', "present ({$mode} mode)");

        if ($factory->isTestMode() && app()->environment('production')) {
            $this->warn_('Mode', 'Production is running against TEST keys — no real money will move.');
            $warnings++;
        }

        $client = $factory->client();

        // ── Account ready for charges ─────────────────────────────────────────
        try {
            $account = $client->accounts->retrieve();

            if ($account->charges_enabled) {
                $this->ok('Account', 'activated for charges');
            } else {
                $this->fail_('Account', 'not activated for charges — the provider will refuse live payments.');
                $failures++;
            }
        } catch (ApiErrorException $e) {
            $this->fail_('Account', 'could not be read: ' . $e->getMessage());
            $failures++;
        }

        // ── Price ─────────────────────────────────────────────────────────────
        $priceId = config('stripe.subscription.price_id');

        if (blank($priceId)) {
            $this->fail_('Price', 'STRIPE_PRICE_ID is not set. Run billing:bootstrap-product.');
            $failures++;
        } else {
            try {
                $price = $client->prices->retrieve($priceId);

                if (! $price->active) {
                    $this->fail_('Price', "{$priceId} exists but is archived.");
                    $failures++;
                } else {
                    $amount = number_format($price->unit_amount / 100, 2);
                    $this->ok('Price', "{$priceId} — {$amount} {$price->currency} / {$price->recurring?->interval}");
                }
            } catch (ApiErrorException $e) {
                $this->fail_('Price', "{$priceId} could not be read: " . $e->getMessage());
                $failures++;
            }
        }

        // ── Webhook secrets ───────────────────────────────────────────────────
        blank(config('stripe.webhook_secret'))
            ? [$this->fail_('Webhook secret', 'STRIPE_WEBHOOK_SECRET is not set — the endpoint refuses every delivery.'), $failures++]
            : $this->ok('Webhook secret', 'present');

        if (blank(config('stripe.webhook_connect_secret'))) {
            $this->warn_('Connect secret', 'STRIPE_WEBHOOK_CONNECT_SECRET is not set — payout events will be refused.');
            $warnings++;
        } else {
            $this->ok('Connect secret', 'present');
        }

        // ── Webhook endpoints registered and pointed here ─────────────────────
        $expected = rtrim((string) ($this->option('url') ?: config('app.url')), '/');

        try {
            $endpoints = $client->webhookEndpoints->all(['limit' => 100]);
            $urls = collect($endpoints->data)
                ->filter(fn ($e) => $e->status === 'enabled')
                ->pluck('url');

            if ($urls->isEmpty()) {
                $this->fail_('Webhooks', 'no enabled endpoints registered at the provider.');
                $failures++;
            } else {
                foreach ([
                    'account' => "{$expected}/api/webhooks/stripe",
                    'connect' => "{$expected}/api/webhooks/stripe/connect",
                ] as $label => $want) {
                    $urls->contains($want)
                        ? $this->ok("Webhook ({$label})", $want)
                        : [$this->warn_("Webhook ({$label})", "not registered for {$want}"), $warnings++];
                }
            }
        } catch (ApiErrorException $e) {
            $this->fail_('Webhooks', 'could not be listed: ' . $e->getMessage());
            $failures++;
        }

        // ── Queue ─────────────────────────────────────────────────────────────
        // Webhook processing is queued, so a sync driver in production means
        // every delivery is processed in the request path.
        if (config('queue.default') === 'sync' && app()->environment('production')) {
            $this->warn_('Queue', 'driver is "sync" — webhooks will process in the request path.');
            $warnings++;
        } else {
            $this->ok('Queue', config('queue.default'));
        }

        return $this->summary($failures, $warnings);
    }

    private function ok(string $label, string $detail): void
    {
        $this->line(sprintf('  <fg=green>✓</> %-20s %s', $label, $detail));
    }

    private function warn_(string $label, string $detail): void
    {
        $this->line(sprintf('  <fg=yellow>!</> %-20s %s', $label, $detail));
    }

    private function fail_(string $label, string $detail): void
    {
        $this->line(sprintf('  <fg=red>✗</> %-20s %s', $label, $detail));
    }

    private function summary(int $failures, int $warnings): int
    {
        $this->line(str_repeat('─', 60));

        if ($failures > 0) {
            $this->error("  {$failures} blocking problem(s), {$warnings} warning(s). Do not open billing.");
            $this->line('');

            return self::FAILURE;
        }

        $warnings > 0
            ? $this->warn("  Ready, with {$warnings} warning(s).")
            : $this->info('  All checks passed.');

        $this->line('');

        return self::SUCCESS;
    }
}
