<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who a row's sponsor actually resolves to, worked out once.
 *
 * The enrollment tree is built by walking the sponsor column, one level at a
 * time, parents before children. That only works if the column being walked is
 * the one the commit will actually use — and it was not.
 *
 * iHub's export names a sponsor on 4,581 rows that is not in the file, because
 * they track recruitment across a system wider than the slice they sent. The
 * commit has always fallen back to the position directly above for those, which
 * is right. But the depth pass treated them as *roots* of the enrollment tree,
 * because the sponsor it could see was absent. So the commit would set their
 * enrollment path first, before the position they actually hang under had one,
 * and 4,581 partners plus everyone beneath them would come out as separate
 * enrollment roots with no upline.
 *
 * The placement tree — the one that pays — was never affected. This is the
 * enrollment tree only, which is why it is quiet enough to have shipped.
 *
 * Fixed by writing the fallback down instead of implying it twice: this column
 * holds the sponsor if the file contains one and the position above otherwise,
 * and both the depth pass and the commit read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_import_rows', function (Blueprint $table) {
            $table->string('effective_sponsor_id', 64)->nullable()->after('external_sponsor_id');
        });

        // The enrollment level pass joins staging to staging on this column,
        // once per level.
        DB::statement(
            'CREATE INDEX partner_import_rows_effective_sponsor_idx
                 ON partner_import_rows (partner_import_id, effective_sponsor_id)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS partner_import_rows_effective_sponsor_idx');

        Schema::table('partner_import_rows', function (Blueprint $table) {
            $table->dropColumn('effective_sponsor_id');
        });
    }
};
