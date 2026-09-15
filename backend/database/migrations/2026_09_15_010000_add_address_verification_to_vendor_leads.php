<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_leads', function (Blueprint $table) {
            /*
             * What the carrier made of the delivery address.
             *
             *   verified | suggested | unverified | unavailable | rejected
             *
             * Null until an address has been checked. See
             * App\Services\Vendor\Shipping\AddressVerification.
             */
            $table->string('address_status', 16)->nullable();
            $table->string('address_status_reason')->nullable();

            // FedEx's delivery classification: RESIDENTIAL, BUSINESS, MIXED or UNKNOWN.
            $table->string('address_classification', 16)->nullable();

            // The corrected address FedEx offered, kept while the buyer decides.
            $table->json('address_suggestion')->nullable();
            $table->timestamp('address_checked_at')->nullable();

            // The buyer chose to ship to an address FedEx did not confirm.
            $table->timestamp('address_confirmed_at')->nullable();

            // An admin checked that buyer-confirmed address before it shipped.
            $table->foreignId('address_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('address_reviewed_at')->nullable();

            $table->index(['address_status', 'address_confirmed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('vendor_leads', function (Blueprint $table) {
            $table->dropIndex(['address_status', 'address_confirmed_at']);
            $table->dropConstrainedForeignId('address_reviewed_by');
            $table->dropColumn([
                'address_status', 'address_status_reason', 'address_classification',
                'address_suggestion', 'address_checked_at', 'address_confirmed_at', 'address_reviewed_at',
            ]);
        });
    }
};
