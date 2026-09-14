<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A permanent record of which account first saved each physical card.
 *
 * The unique fingerprint on `payment_methods` only covers cards an account still
 * holds, so removing a card, or deleting the account, freed it for another
 * account. See App\Models\CardFingerprint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_fingerprints', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint')->unique();
            // Null once the account is deleted. The card stays claimed.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('first_seen_at');
            $table->timestamps();
        });

        // Cards already on file belong to whichever account saved them first.
        DB::table('payment_methods')
            ->whereNotNull('fingerprint')
            ->orderBy('created_at')
            ->orderBy('id')
            ->each(function ($row) {
                DB::table('card_fingerprints')->insertOrIgnore([
                    'fingerprint'   => $row->fingerprint,
                    'user_id'       => $row->user_id,
                    'first_seen_at' => $row->created_at ?? now(),
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_fingerprints');
    }
};
