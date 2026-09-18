<?php

namespace App\Console\Commands;

use App\Models\PartnerCompany;
use App\Models\PartnerWebhookDelivery;
use App\Models\User;
use App\Services\Partner\PartnerWebhookDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Configure and exercise a partner company's claim webhook.
 *
 * The signing secret is encrypted on the row, so it cannot be set with SQL, and
 * it should not be typed into a shell where it lands in history. This generates
 * one, stores it, and prints it once — the only moment it is readable, the same
 * way a reissued activation code works.
 *
 * `--resend-claims` exists because of a real gap: a position claimed while the
 * webhook was switched off produces no event and there is nothing to replay,
 * since a delivery is only recorded when the integration is live. Those claims
 * are real and the partner still needs to know about them.
 */
class PartnerWebhookCommand extends Command
{
    protected $signature = 'partners:webhook
                            {company : Partner company slug}
                            {--secret= : Set this signing secret (or --generate)}
                            {--generate : Generate a signing secret and print it once}
                            {--enable : Start sending claim events}
                            {--disable : Stop sending claim events}
                            {--ping : Send a test event}
                            {--resend-claims : Create events for claims that never produced one}';

    protected $description = 'Configure or exercise a partner company claim webhook';

    public function handle(PartnerWebhookDispatcher $dispatcher): int
    {
        $company = PartnerCompany::where('slug', $this->argument('company'))->first();

        if ($company === null) {
            $this->error("No partner company with slug '{$this->argument('company')}'.");

            return self::FAILURE;
        }

        if ($this->option('generate') || $this->option('secret')) {
            $this->setSecret($company);
        }

        if ($this->option('enable') || $this->option('disable')) {
            $company->forceFill(['webhook_enabled' => (bool) $this->option('enable')])->save();
            $this->info('Claim events are now ' . ($company->webhook_enabled ? 'ON' : 'OFF') . '.');
        }

        $this->status($company->refresh());

        if ($this->option('resend-claims')) {
            $this->resendClaims($company, $dispatcher);
        }

        if ($this->option('ping')) {
            if (! $company->webhookConfigured()) {
                $this->error('Nothing to send to — the webhook needs a URL, a secret, and to be enabled.');

                return self::FAILURE;
            }

            $delivery = $dispatcher->ping($company);
            $this->info("Test event {$delivery->event_id} queued.");
        }

        return self::SUCCESS;
    }

    private function setSecret(PartnerCompany $company): void
    {
        $secret = (string) ($this->option('secret') ?: Str::random(48));

        if (strlen($secret) < 16) {
            $this->error('A signing secret needs at least 16 characters.');

            return;
        }

        $company->forceFill(['webhook_secret' => $secret])->save();

        $this->newLine();
        $this->warn('  Signing secret — shown once, stored encrypted, never displayed again:');
        $this->line("  {$secret}");
        $this->warn('  Give this to ' . $company->name . '. They verify every delivery with it.');
        $this->newLine();
    }

    private function status(PartnerCompany $company): void
    {
        $this->table(['', ''], [
            ['Company',        $company->name],
            ['Endpoint',       $company->webhook_url ?: 'not set'],
            ['Signature',      PartnerCompany::SIGNATURE_STYLES[$company->webhook_signature_style] ?? '—'],
            ['Secret',         filled($company->webhook_secret) ? 'set' : 'NOT SET'],
            ['Sending',        $company->webhook_enabled ? 'yes' : 'no'],
            ['Contact details', $company->webhook_include_contact ? 'included' : 'withheld'],
            ['Last delivered', $company->webhook_last_success_at?->diffForHumans() ?? 'never'],
            ['Failures in a row', (int) $company->webhook_consecutive_failures],
        ]);
    }

    /**
     * Create events for positions claimed while the webhook was off.
     *
     * A delivery is only recorded when the integration is live, so a claim that
     * happened before the secret was set left no trace to replay. The claims
     * themselves are real, and the partner needs them.
     */
    private function resendClaims(PartnerCompany $company, PartnerWebhookDispatcher $dispatcher): void
    {
        if (! $company->webhookConfigured()) {
            $this->error('Cannot resend: the webhook is not configured and enabled.');

            return;
        }

        $missing = User::query()
            ->where('partner_company_id', $company->id)
            ->whereNotNull('claimed_at')
            ->whereNotExists(fn ($q) => $q
                ->from('partner_webhook_deliveries')
                ->whereColumn('partner_webhook_deliveries.user_id', 'users.id')
                ->where('event_type', PartnerWebhookDelivery::EVENT_SPOT_CLAIMED))
            ->orderBy('claimed_at')
            ->get();

        if ($missing->isEmpty()) {
            $this->line('No claims are missing an event.');

            return;
        }

        $this->line("Creating events for {$missing->count()} claim(s) that never produced one…");

        foreach ($missing as $spot) {
            $delivery = $dispatcher->spotClaimed($spot, $spot->mergedInto);

            $this->line("  {$spot->external_user_id} → " . ($delivery?->event_id ?? 'not sent'));
        }
    }
}
