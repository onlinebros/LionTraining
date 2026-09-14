<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_leads', function (Blueprint $table) {
            $table->id();

            /*
             * The attribution key. Sent to the vendor as Stripe's
             * `client_reference_id` and echoed back on the confirmation, so it
             * is the one value that ties their payment to our partner.
             *
             * Constrained to Stripe's allowed charset for that field (letters,
             * digits, dash, underscore) and kept short enough to stay readable
             * in a dashboard a human is scanning by eye.
             */
            $table->string('public_ref', 32)->unique();

            // Which registry entry in config/vendors.php this came from.
            $table->string('vendor', 64);
            $table->string('product_key', 64);

            /*
             * The partner who earns from this.
             *
             * `referral_code` is a SNAPSHOT, not a join. A partner can be
             * deleted or have their code reissued, and neither must be able to
             * rewrite history on an order the vendor has already paid us for.
             * member_id nulls out; the code stays as the audit trail.
             */
            $table->foreignId('member_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('referral_code', 12)->nullable();

            // The lead also lands in the partner's CRM so it can be worked.
            $table->foreignId('crm_contact_id')->nullable()->constrained('crm_contacts')->nullOnDelete();

            // ── The customer, as captured by us ───────────────────────────────
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('company')->nullable();

            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country', 2)->nullable();

            $table->unsignedInteger('quantity')->default(1);
            $table->text('notes')->nullable();

            // Per-product qualifying answers (property type, system size, …).
            // JSON because the questions are per-product config and adding one
            // must not be a migration.
            $table->json('qualifiers')->nullable();

            /*
             * new → handed_off → converted → refunded
             *                 ↘ lost
             *
             * A plain string, not an enum: the vendor pipeline will grow states
             * (quoted, site_survey, installed) and on Postgres an enum change is
             * a constraint rebuild for what is really just a label.
             */
            $table->string('status', 32)->default('new');

            $table->timestamp('handed_off_at')->nullable();
            $table->text('checkout_url')->nullable();   // exact URL sent, for audit

            // ── The vendor's confirmation — their numbers, not ours ───────────
            $table->string('vendor_order_ref', 191)->nullable();
            $table->string('provider_session_id', 191)->nullable()->unique();
            $table->string('provider_payment_intent_id', 191)->nullable();
            $table->unsignedBigInteger('amount_total')->nullable();  // minor units
            $table->string('currency', 3)->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            /*
             * How the conversion was established. A commission raised from a
             * signed webhook and one typed in by an admin are not equally
             * trustworthy, and a dispute six months later turns on knowing which
             * this was.
             */
            $table->string('confirmed_via', 32)->nullable();  // webhook | manual | import | reconciliation
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();

            // Set when the commission credit is raised, so it can only happen once.
            $table->foreignId('commission_ledger_id')->nullable();

            // ── Provenance ────────────────────────────────────────────────────
            $table->string('source', 32)->default('member_page');
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('utm')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['vendor', 'status']);
            $table->index(['member_id', 'status']);
            // Drives the email fallback match, which is always scoped by status.
            $table->index(['email', 'status']);
        });

        /*
         * The webhook ledger now carries a third kind of endpoint. Ours are
         * 'account' and 'connect'; a vendor's is 'vendor:<slug>', which does not
         * fit the original 16 characters.
         *
         * The prefix is load-bearing, not cosmetic: ProcessPaymentWebhookJob
         * routes on it, and an event from someone else's Stripe account must
         * never reach the processor that resolves objects against ours.
         */
        Schema::table('stripe_webhook_events', function (Blueprint $table) {
            $table->string('endpoint', 64)->default('account')->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_leads');

        Schema::table('stripe_webhook_events', function (Blueprint $table) {
            $table->string('endpoint', 16)->default('account')->change();
        });
    }
};
