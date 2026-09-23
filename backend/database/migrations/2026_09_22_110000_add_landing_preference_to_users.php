<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an account lands after signing in.
 *
 * Only meaningful for people who hold more than one section: an admin (admin
 * panel, member area, and the partner portal if they are looking at it), and a
 * product partner who also sells (portal or member area). An ordinary member
 * has one place to be and never sees the setting.
 *
 * Null means "whatever suits this kind of account", which is what every
 * existing row wants and is why nothing is backfilled. The defaults live in
 * User::landingRoute(), not here, because they are a product decision and this
 * column only records a person overriding them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             * A short key — 'admin', 'member', 'portal' — rather than a route
             * name. Route names get renamed; a stored one would send somebody
             * to a 500 on the day it did. An unrecognised key here falls back
             * to the default, so a retired section degrades quietly.
             */
            $table->string('landing_preference', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('landing_preference');
        });
    }
};
