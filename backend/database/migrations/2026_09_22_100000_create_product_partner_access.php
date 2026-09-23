<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Product Partner: the vendor's own people, inside our back office.
 *
 * A product partner is not a member and not an admin. They are somebody at
 * PlasmaGuard who needs to see how their product is selling, what Quantum 3 is
 * owed on it, and what they have paid against that — without being able to see
 * the membership business, the genealogy, or another vendor's numbers.
 *
 * Three pieces of state:
 *
 *   roles.product_partner            the role itself, non-admin, so every
 *                                    existing `admin` check keeps them out by
 *                                    default rather than by a new exception.
 *   product_partner_assignments      which vendor and which products each one
 *                                    may see. The role alone grants nothing —
 *                                    an account with the role and no assignment
 *                                    reaches a page that says so.
 *   product_partner_payments         what they say they have paid us, and
 *                                    whether we agree. The whole reason for the
 *                                    section is one shared number.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Level 5: above a paid member, below support (10). `is_admin` is false
         * and must stay false — the portal has its own middleware, and a role
         * flagged admin would silently open /admin as well.
         *
         * Inserted here rather than left to the seeder because production is
         * migrated, not seeded, and a role nobody can assign is the same as no
         * feature. Idempotent so re-running against a seeded database is safe.
         */
        if (! DB::table('roles')->where('name', 'product_partner')->exists()) {
            DB::table('roles')->insert([
                'name'         => 'product_partner',
                'display_name' => 'Product Partner',
                'description'  => 'A vendor whose products we sell. Sees their own sales, pipeline and settlement — nothing else.',
                'is_admin'     => false,
                'level'        => 5,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        Schema::create('product_partner_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * Keys into config/vendors.php, not foreign keys: the vendor
             * registry is config, so there is no table to point at. A row whose
             * vendor has been removed from the registry grants nothing rather
             * than breaking a login — see App\Support\ProductPartner.
             */
            $table->string('vendor', 64);

            /*
             * '*' means every product this vendor has now or later. A specific
             * key narrows it to one SKU, which is what makes this "linked to
             * certain sale products" rather than "linked to a company".
             *
             * NOT NULL with a sentinel rather than nullable: on Postgres two
             * NULLs are distinct, so a nullable column would let the same
             * "all products" grant be inserted twice and the unique index below
             * would not stop it.
             */
            $table->string('product_key', 64)->default('*');

            // Who let them in. A vendor gaining sight of our pipeline is a
            // commercial decision, and "who agreed to this" outlives the people.
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['user_id', 'vendor', 'product_key']);
            $table->index('vendor');
        });

        Schema::create('product_partner_payments', function (Blueprint $table) {
            $table->id();

            $table->string('vendor', 64);

            /*
             * Recorded by the vendor, confirmed by us. Deliberately two steps:
             * the point of the screen is that both sides see the same number,
             * and a debtor who can mark their own debt settled produces a
             * number only one side believes.
             */
            $table->string('status', 16)->default('pending');   // pending | confirmed | rejected

            // Minor units, like every other money column in this schema.
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('USD');

            // The day the money left them, which is rarely the day they told us.
            $table->date('paid_on');

            // Their reference for it — a wire confirmation, a cheque number.
            $table->string('reference', 100)->nullable();
            $table->string('method', 40)->nullable();           // wire | ach | check | other

            /*
             * Which of our invoices this pays, when they know. Matches
             * vendor_leads.invoice_reference, and confirming a payment that
             * names one settles those orders in the same transaction.
             */
            $table->string('invoice_reference', 100)->nullable();

            $table->text('note')->nullable();

            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            // Why we did not agree. Shown to the vendor: a rejection with no
            // reason is a support ticket by another name.
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->index(['vendor', 'status']);
            $table->index('invoice_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_partner_payments');
        Schema::dropIfExists('product_partner_assignments');

        // Accounts still on the role would be left pointing at nothing, so they
        // go back to being free members rather than to a dangling role_id.
        $role = DB::table('roles')->where('name', 'product_partner')->first();

        if ($role) {
            $free = DB::table('roles')->where('name', 'free_member')->first();

            if ($free) {
                DB::table('users')->where('role_id', $role->id)->update(['role_id' => $free->id]);
            }

            DB::table('roles')->where('id', $role->id)->delete();
        }
    }
};
