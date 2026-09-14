<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_leads', function (Blueprint $table) {
            /*
             * Who a sale is for, kept apart from whose link it came through.
             *
             *   member_id           the partner whose share link was used
             *   buyer_user_id       the partner who bought for themselves, if one did
             *   credited_member_id  who the sale counts for (their sales, promotions)
             *   earner_id           who the commission is paid to
             *
             * For a customer sale the link owner is all three. For a partner's
             * own purchase the sale counts for the buyer and the commission goes
             * to the buyer's sponsor, so no partner is paid on their own order.
             *
             * customer | self | review — see App\Services\Vendor\PurchaseAttribution.
             */
            $table->string('attribution', 16)->default('customer');
            $table->string('attribution_reason')->nullable();
            $table->foreignId('buyer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('credited_member_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('earner_id')->nullable()->constrained('users')->nullOnDelete();

            // An admin's decision on a held order. Once set, later confirmations
            // leave the attribution alone.
            $table->foreignId('attribution_resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('attribution_resolved_at')->nullable();

            $table->index(['earner_id', 'status']);
            $table->index(['credited_member_id', 'status']);
            // Promotion standings read one product's confirmed sales in order.
            $table->index(['vendor', 'product_key', 'status', 'converted_at']);
        });

        // Everything captured before this was treated as a customer sale. Any
        // commission already raised on it stays with whoever it was raised for.
        DB::table('vendor_leads')->update([
            'credited_member_id' => DB::raw('member_id'),
            'earner_id'          => DB::raw('member_id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('vendor_leads', function (Blueprint $table) {
            $table->dropIndex(['earner_id', 'status']);
            $table->dropIndex(['credited_member_id', 'status']);
            $table->dropIndex(['vendor', 'product_key', 'status', 'converted_at']);

            $table->dropConstrainedForeignId('buyer_user_id');
            $table->dropConstrainedForeignId('credited_member_id');
            $table->dropConstrainedForeignId('earner_id');
            $table->dropConstrainedForeignId('attribution_resolved_by');

            $table->dropColumn(['attribution', 'attribution_reason', 'attribution_resolved_at']);
        });
    }
};
