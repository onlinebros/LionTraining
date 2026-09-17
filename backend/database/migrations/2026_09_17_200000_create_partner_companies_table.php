<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A partner company whose existing membership list is being brought across.
 *
 * One row per company we sign an arrangement with. It owns two things: the
 * branding on the claim page its people land on, and the namespace their
 * identifiers live in. That namespace matters — two partner companies will both
 * have a member numbered 1001, and the pair (company, external user id) is what
 * has to be unique, never the id on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_companies', function (Blueprint $table) {
            $table->id();

            // The slug is the whole public URL: /partner/{slug}. Short, because
            // it gets printed in the partner's own emails and read down a phone.
            $table->string('slug', 64)->unique();
            $table->string('name');

            // Branding for the claim page. Two logos because the member theme
            // ships light and dark, and a logo that works on one usually does
            // not on the other.
            $table->string('logo_path')->nullable();
            $table->string('logo_dark_path')->nullable();

            // Copy on the claim page. Left nullable so a company can go live on
            // defaults and have marketing fill these in afterwards.
            $table->string('headline')->nullable();
            $table->text('intro')->nullable();
            $table->string('support_email')->nullable();

            // What the company calls the two credentials. Their people know
            // these by the partner's own names for them ("IBO Number",
            // "Distributor ID"), and a claim page asking for a "UserID" when
            // their card says "Member Number" is a support ticket per person.
            $table->string('identifier_label', 64)->default('User ID');
            $table->string('activation_label', 64)->default('Activation Code');

            // Off by default. A company exists here from the moment we start
            // preparing its import, which is well before its people should be
            // able to reach a claim page.
            $table->boolean('is_active')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_companies');
    }
};
