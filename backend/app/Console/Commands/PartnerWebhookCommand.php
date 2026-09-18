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
                            {--resend-claims : Create events for claims that never produced one}
                            {--show= : Print the exact request for one delivery id, for the partner to compare against}
                            {--test-vector : Print a worked example with a throwaway secret, to check their algorithm}';

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

        if ($this->option('test-vector')) {
            $this->testVector($dispatcher, $company);

            return self::SUCCESS;
        }

        if ($this->option('show')) {
            return $this->show($dispatcher, (int) $this->option('show'));
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

    /**
     * Everything about one delivery, exactly as it went out.
     *
     * For the conversation that starts "your signature does not match ours".
     * Both sides need to be looking at the same bytes, and the body we signed is
     * frozen on the delivery row, so this is that body and not a rebuilt
     * approximation of it.
     */
    private function show(PartnerWebhookDispatcher $dispatcher, int $id): int
    {
        $delivery = PartnerWebhookDelivery::with('company')->find($id);

        if ($delivery === null || $delivery->company === null) {
            $this->error("No delivery {$id}.");

            return self::FAILURE;
        }

        $body    = $dispatcher->encode($delivery);
        $headers = $dispatcher->headers($delivery->company, $delivery, $body);

        $this->newLine();
        $this->line('POST ' . $delivery->company->webhook_url);

        foreach ($headers as $name => $value) {
            $this->line("{$name}: {$value}");
        }

        $this->newLine();
        $this->line($body);
        $this->newLine();

        $this->table(['', ''], [
            ['Body length (bytes)', strlen($body)],
            ['Body SHA-256',        hash('sha256', $body)],
            ['Attempts',            $delivery->attempts],
            ['Their response',      $delivery->response_status ?? '—'],
            ['Their body',          \Illuminate\Support\Str::limit((string) $delivery->response_body, 200)],
        ]);

        $this->warn('The signature above is over the raw body exactly as printed — no re-encoding,');
        $this->warn('no pretty-printing, no trailing newline.');

        return self::SUCCESS;
    }

    /**
     * A worked example against a throwaway secret.
     *
     * The fastest way to tell a wrong secret from a wrong algorithm. If the
     * partner's code reproduces this digest, their algorithm agrees with ours
     * and the problem is which secret each side holds. If it does not, the
     * algorithm is where to look, and no amount of re-sending the secret helps.
     */
    private function testVector(PartnerWebhookDispatcher $dispatcher, PartnerCompany $company): void
    {
        $secret = 'quantum3-test-secret-do-not-use-in-production';
        $body   = '{"id":"00000000-0000-4000-8000-000000000000","type":"ping","created":1700000000,"data":{"message":"test vector"}}';

        $this->newLine();
        $this->line('Secret:');
        $this->line('  ' . $secret);
        $this->line('Body (exact bytes, ' . strlen($body) . ' of them, no trailing newline):');
        $this->line('  ' . $body);
        $this->newLine();
        $this->line('Expected ' . ($company->webhook_signature_style === PartnerCompany::SIGNATURE_SHA256
            ? 'X-Partner-Signature'
            : 'Q3-Signature (v1 part)') . ':');
        $this->line('  sha256=' . hash_hmac('sha256', $body, $secret));
        $this->newLine();
        $this->warn('If their code produces that digest, the algorithm agrees and the secret differs.');
        $this->warn('If it does not, the algorithm differs and the secret is not the problem.');
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
