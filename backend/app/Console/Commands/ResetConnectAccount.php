<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Stripe\StripeClientFactory;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Console\Command;
use Stripe\Exception\ApiErrorException;

/**
 * Delete a partner's connected account so it is recreated on their next visit.
 *
 * `controller.stripe_dashboard.type` is immutable once an account exists, so
 * changing STRIPE_CONNECT_REQUIREMENT_COLLECTION does nothing for existing
 * accounts; the only remedy Stripe offers is a new account. Also the way to
 * start a partner over when they set up the wrong person or business.
 *
 * One partner at a time, and refuses an account holding money: deleting a
 * connected account cannot be undone.
 */
class ResetConnectAccount extends Command
{
    protected $signature = 'connect:reset-account
                            {email : The partner whose payout account should be rebuilt}
                            {--force : Skip the confirmation prompt}
                            {--keep-remote : Clear our records but leave the Stripe account in place}';

    protected $description = 'Delete a partner\'s Stripe connected account so it is recreated under the current settings';

    public function handle(StripeConnectService $connect, StripeClientFactory $factory): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("No user with email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $accountId = $user->stripe_connect_account_id;

        if (! $accountId) {
            $this->info("{$user->email} has no payout account. Nothing to reset.");

            return self::SUCCESS;
        }

        $this->line("Partner: {$user->email}");
        $this->line("Account: {$accountId}");

        try {
            $account = $factory->client()->accounts->retrieve($accountId, []);
            $wanted  = $connect->platformOwnsRequirements() ? 'application' : 'stripe';

            $this->line('Requirement collection: '.($account->controller->requirement_collection ?? 'unknown')." (settings want: {$wanted})");
            $this->line('Payouts enabled: '.($account->payouts_enabled ? 'yes' : 'no'));

            // Money still on the account would be lost with it.
            $balance = $factory->client()->balance->retrieve([], ['stripe_account' => $accountId]);
            $held = collect($balance->available)->concat($balance->pending)->sum(fn ($b) => $b->amount);

            if ($held > 0) {
                $this->error('The account holds $'.number_format($held / 100, 2).'. Pay it out before resetting.');

                return self::FAILURE;
            }
        } catch (ApiErrorException $e) {
            $this->warn('Could not read the account from Stripe: '.$e->getMessage());

            if (! $this->option('force') && ! $this->confirm('Clear our records without confirming the Stripe side?', false)) {
                return self::FAILURE;
            }
        }

        if (! $this->option('force') && ! $this->confirm("Delete {$accountId} and clear {$user->email}'s payout setup?", false)) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        if (! $this->option('keep-remote')) {
            try {
                $factory->client()->accounts->delete($accountId);
                $this->info("Deleted {$accountId} from Stripe.");
            } catch (ApiErrorException $e) {
                $this->error('Stripe refused the delete: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        $user->connectIdentityClaims()->delete();

        $user->stripe_connect_account_id = null;
        $user->connect_charges_enabled = false;
        $user->connect_payouts_enabled = false;
        $user->connect_details_submitted = false;
        $user->connect_tax_reporting_status = null;
        $user->connect_requirements = null;
        $user->connect_synced_at = null;
        $user->save();

        $this->info("Cleared. {$user->email} starts a new payout account on their next visit to Get Paid.");

        return self::SUCCESS;
    }
}
