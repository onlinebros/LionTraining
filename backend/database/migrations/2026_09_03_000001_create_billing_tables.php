<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Subscriptions and saved cards.
 *
 * Both tables are a *mirror* of the payment provider, never the source of
 * truth. The provider decides what state a subscription is in; these rows exist
 so screens can be rendered without an API call, and the webhook ledger keeps
 * them in step. Anything that reads these rows to make a money decision should
 * be reading the provider instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('provider_customer_id')->nullable()->unique()->after('referral_code');
            // May legitimately differ from the login email.
            $table->string('billing_email')->nullable()->after('provider_customer_id');
            // Founders, staff and comped partners skip the subscription gate.
            $table->boolean('billing_exempt')->default(false)->after('billing_email');
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('provider')->default('stripe');
            $table->string('provider_subscription_id')->unique();
            $table->string('provider_price_id')->nullable();

            // trialing | active | past_due | canceled | incomplete | unpaid
            $table->string('status', 32)->index();

            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_ends_at')->nullable();

            // A subscription opened during pre-launch sits on a placeholder trial
            // until the launch date is known; this flag is how the apply command
            // finds them again.
            $table->boolean('is_prelaunch_trial')->default(false)->index();

            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            $table->string('default_payment_method_id')->nullable();
            $table->string('latest_invoice_id')->nullable();

            // Minor units, as the provider reports them. Integer, not float —
            // money in a float is a rounding bug waiting for a large enough team.
            $table->unsignedBigInteger('amount')->nullable();
            $table->char('currency', 3)->nullable();

            // Stale values here are the signal that webhooks are being missed.
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('provider_payment_method_id')->unique();

            $table->string('brand', 32)->nullable();
            $table->string('last4', 4)->nullable();
            $table->unsignedSmallInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('funding', 16)->nullable();

            // Always populated, so switching enforcement on protects cards
            // captured while it was off.
            $table->string('fingerprint')->nullable()->index();

            // Carries the constraint, and only populated while enforcement is
            // on. Nullable because Postgres treats NULLs as distinct, so rows
            // captured with enforcement off do not collide with each other.
            $table->string('unique_fingerprint')->nullable();

            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique('unique_fingerprint');
            $table->index(['user_id', 'is_default']);
        });

        // Extend the existing webhook ledger to what C2 specifies.
        Schema::table('stripe_webhook_events', function (Blueprint $table) {
            $table->boolean('signature_valid')->default(true)->after('signature');
            // 'account' | 'connect' — which endpoint and secret it arrived on.
            $table->string('endpoint', 16)->default('account')->after('signature_valid');
            $table->text('processing_error')->nullable()->after('endpoint');
        });

        // A rejected signature is stored for inspection rather than dropped, so
        // the column has to allow the payload of something that failed auth.
        DB::statement('CREATE INDEX IF NOT EXISTS stripe_webhook_events_type_received_idx ON stripe_webhook_events (type, received_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS stripe_webhook_events_type_received_idx');

        Schema::table('stripe_webhook_events', function (Blueprint $table) {
            $table->dropColumn(['signature_valid', 'endpoint', 'processing_error']);
        });

        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('subscriptions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['provider_customer_id', 'billing_email', 'billing_exempt']);
        });
    }
};
