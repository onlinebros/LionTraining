<?php

namespace Tests\Feature\Partner;

use App\Models\PartnerCompany;
use App\Models\PartnerImport;
use App\Models\PartnerImportRow;
use App\Models\Role;
use App\Models\User;
use App\Services\Partner\ActivationCode;
use App\Services\Genealogy\EnrollmentService;
use App\Services\Partner\SpotImportCommitter;
use App\Services\Partner\SpotImportParser;
use App\Services\Partner\SpotImportValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Staging a partner company's list, checking it, and turning it into positions.
 *
 * The bar these tests hold: nothing reaches the genealogy that an admin has not
 * been shown, and anything that would reach it wrongly stops the whole batch.
 * Placements are permanent, so "we noticed afterwards" is not a recovery.
 */
class SpotImportTest extends TestCase
{
    use RefreshDatabase;

    private PartnerCompany $company;
    private User $founder;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => Role::FREE_MEMBER, 'display_name' => 'Free Member', 'is_admin' => false, 'level' => 1]);

        $this->company = PartnerCompany::create([
            'slug' => 'acme',
            'name' => 'Acme Group',
        ]);

        $this->founder = User::factory()->create(['email' => 'founder@quantum3.test']);
        app(EnrollmentService::class)->enroll($this->founder, null);
        $this->founder->refresh();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function stage(string $csv): PartnerImport
    {
        $path = tempnam(sys_get_temp_dir(), 'spot-import-') . '.csv';
        file_put_contents($path, $csv);

        $import = PartnerImport::create([
            'partner_company_id' => $this->company->id,
            'original_filename'  => 'list.csv',
        ]);

        app(SpotImportParser::class)->parse($import, $path);
        unlink($path);

        return $import->refresh();
    }

    private function validate(PartnerImport $import): PartnerImport
    {
        return app(SpotImportValidator::class)->validate($import);
    }

    private function commit(PartnerImport $import): int
    {
        return app(SpotImportCommitter::class)->commit($import);
    }

    /** Point a leg at our founder so the batch can commit. */
    private function linkTop(PartnerImport $import, string $externalId): void
    {
        $import->rows()->where('external_user_id', $externalId)
            ->update(['parent_user_id' => $this->founder->id]);
    }

    private function header(): string
    {
        return "external_user_id,activation_code,external_parent_id,external_sponsor_id\n";
    }

    // ── Parsing ───────────────────────────────────────────────────────────────

    public function test_a_file_is_staged_without_creating_anything(): void
    {
        $before = User::count();

        $import = $this->stage($this->header()
            . "A-1,CODE-ALPHA,,\n"
            . "A-2,CODE-BETA,A-1,\n");

        $this->assertSame(2, $import->total_rows);
        $this->assertSame(2, $import->rows()->count());
        // The whole point of staging: an upload is not a commitment.
        $this->assertSame($before, User::count());
    }

    public function test_headers_are_matched_however_the_partner_spelled_them(): void
    {
        $import = $this->stage(
            "\xEF\xBB\xBF\"User ID\",Activation Code,Upline ID,Enroller ID\n"
            . "A-1,CODE-ALPHA,,\n"
            . "A-2,CODE-BETA,A-1,A-1\n"
        );

        // A BOM from Excel plus the partner's own column names must not be the
        // reason a perfectly good list is rejected.
        $this->assertNotSame(PartnerImport::STATUS_FAILED, $import->status);

        $row = $import->rows()->where('external_user_id', 'A-2')->first();

        $this->assertSame('A-1', $row->external_parent_id);
        $this->assertSame('A-1', $row->external_sponsor_id);
    }

    public function test_a_file_missing_a_required_column_is_refused_whole(): void
    {
        $import = $this->stage("external_user_id,external_parent_id\nA-1,\n");

        $this->assertSame(PartnerImport::STATUS_FAILED, $import->status);
        $this->assertStringContainsString('activation_code', $import->errors[0]);
        $this->assertSame(0, $import->rows()->count());
    }

    public function test_personal_data_in_an_unmapped_column_is_discarded_not_stored(): void
    {
        // A partner exports what their system exports. We take the four columns
        // we asked for and the rest never lands in the database — a `raw`
        // payload "just in case" is how the data we declined to receive gets
        // stored anyway.
        $import = $this->stage(
            "external_user_id,activation_code,external_parent_id,first_name,email,phone\n"
            . "A-1,CODE-ALPHA,,Dana,dana@example.com,555-0142\n"
        );

        $row = $import->rows()->first();

        $this->assertSame('A-1', $row->external_user_id);
        $this->assertStringNotContainsString('Dana', json_encode($row->toArray()));
        $this->assertStringNotContainsString('dana@example.com', json_encode($row->toArray()));
        $this->assertStringNotContainsString('555-0142', json_encode($row->toArray()));
    }

    public function test_the_names_of_discarded_columns_are_reported_back(): void
    {
        $import = $this->stage(
            "external_user_id,activation_code,first_name,email\n"
            . "A-1,CODE-ALPHA,Dana,dana@example.com\n"
        );

        // Names only — kept so an admin can tell the partner what they sent and
        // we threw away, which is impossible if discarding left no trace.
        $this->assertSame(['first_name', 'email'], $import->ignored_columns);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    public function test_a_parent_that_is_not_in_the_file_fails_that_row(): void
    {
        $import = $this->validate($this->stage($this->header()
            . "A-1,CODE-ALPHA,,\n"
            . "A-2,CODE-BETA,A-999,\n"));

        $row = $import->rows()->where('external_user_id', 'A-2')->first();

        $this->assertSame(PartnerImportRow::STATUS_INVALID, $row->status);
        $this->assertStringContainsString('parent that is not in the file', $row->errors[0]);
        $this->assertSame(PartnerImport::STATUS_FAILED, $import->status);
    }

    public function test_a_parent_cycle_fails_every_row_on_it(): void
    {
        $import = $this->validate($this->stage($this->header()
            . "A-1,CODE-ALPHA,A-3,\n"
            . "A-2,CODE-BETA,A-1,\n"
            . "A-3,CODE-GAMMA,A-2,\n"));

        $this->assertSame(PartnerImport::STATUS_FAILED, $import->status);
        $this->assertSame(3, $import->rows()->where('status', PartnerImportRow::STATUS_INVALID)->count());
        $this->assertStringContainsString('never reach the top of a leg', $import->errors[0]);
    }

    public function test_a_duplicate_activation_code_fails_both_rows(): void
    {
        $import = $this->validate($this->stage($this->header()
            . "A-1,SAME-CODE,,\n"
            . "A-2,SAME-CODE,A-1,\n"));

        // One leaked code must not open two positions.
        foreach (['A-1', 'A-2'] as $id) {
            $row = $import->rows()->where('external_user_id', $id)->first();
            $this->assertSame(PartnerImportRow::STATUS_INVALID, $row->status);
            $this->assertStringContainsString('unique', $row->errors[0]);
        }
    }

    public function test_a_code_short_enough_to_guess_is_refused(): void
    {
        $import = $this->validate($this->stage($this->header()
            . "A-1,123,,\n"));

        $this->assertStringContainsString('too short', $import->rows()->first()->errors[0]);
    }

    public function test_a_duplicate_partner_id_is_refused_at_the_door(): void
    {
        // Caught by the staging table's unique index rather than by a set of a
        // million ids held in memory. The second row never lands, so the batch
        // is rejected on the count and the file has to be fixed and re-uploaded.
        $import = $this->stage($this->header()
            . "A-1,CODE-ALPHA,,\n"
            . "A-1,CODE-BETA,,\n");

        $this->assertSame(PartnerImport::STATUS_FAILED, $import->status);
        $this->assertStringContainsString('not unique', $import->errors[0]);
        $this->assertStringContainsString("'A-1' on lines 1 and 2", $import->errors[1]);

        // And re-checking cannot talk it back into health.
        $this->assertSame(PartnerImport::STATUS_FAILED, $this->validate($import)->status);
    }

    public function test_reimporting_an_id_this_company_already_has_fails(): void
    {
        $import = $this->stage($this->header() . "A-1,CODE-ALPHA,,\n");
        $this->validate($import);
        $this->linkTop($import, 'A-1');
        $this->commit($import->refresh());

        $second = $this->validate($this->stage($this->header() . "A-1,CODE-OTHER,,\n"));

        $this->assertStringContainsString('already has a position with this id', $second->rows()->first()->errors[0]);
    }

    // ── Committing ────────────────────────────────────────────────────────────

    public function test_committing_builds_the_tree_the_file_described(): void
    {
        $import = $this->stage($this->header()
            . "A-1,CODE-ALPHA,,\n"
            . "A-2,CODE-BETA,A-1,\n"
            . "A-3,CODE-GAMMA,A-2,\n");

        $this->validate($import);
        $this->linkTop($import, 'A-1');
        $this->validate($import->refresh());

        $this->assertSame(3, $this->commit($import->refresh()));

        $spots = User::query()->holding()->get()->keyBy('external_user_id');

        $this->assertCount(3, $spots);
        $this->assertSame($this->founder->id, $spots['A-1']->placement_parent_id);
        $this->assertSame($spots['A-1']->id, $spots['A-2']->placement_parent_id);
        $this->assertSame($spots['A-2']->id, $spots['A-3']->placement_parent_id);

        // Paths, not just parent ids — the paths are what every downline query
        // actually reads.
        $this->assertSame(
            "{$this->founder->id}.{$spots['A-1']->id}.{$spots['A-2']->id}.{$spots['A-3']->id}",
            $spots['A-3']->placement_path,
        );
    }

    public function test_children_listed_above_their_parents_still_commit(): void
    {
        // Somebody else's export ordering is not an error.
        $import = $this->stage($this->header()
            . "A-3,CODE-GAMMA,A-2,\n"
            . "A-2,CODE-BETA,A-1,\n"
            . "A-1,CODE-ALPHA,,\n");

        $this->validate($import);
        $this->linkTop($import, 'A-1');
        $this->validate($import->refresh());
        $this->commit($import->refresh());

        $spots = User::query()->holding()->get()->keyBy('external_user_id');

        $this->assertSame($spots['A-2']->id, $spots['A-3']->placement_parent_id);
    }

    public function test_an_unconnected_leg_blocks_the_whole_commit(): void
    {
        $import = $this->validate($this->stage($this->header()
            . "A-1,CODE-ALPHA,,\n"
            . "A-2,CODE-BETA,A-1,\n"));

        $this->assertFalse($import->isCommittable());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not connected to anyone/');

        $this->commit($import);
    }

    public function test_a_failed_batch_cannot_be_committed(): void
    {
        $import = $this->validate($this->stage($this->header()
            . "A-1,CODE-ALPHA,A-999,\n"));

        $this->expectException(RuntimeException::class);

        $this->commit($import);
    }

    public function test_committed_spots_hold_a_hashed_code_and_staging_keeps_no_plaintext(): void
    {
        $import = $this->stage($this->header() . "A-1,CODE-ALPHA,,\n");
        $this->validate($import);
        $this->linkTop($import, 'A-1');
        $this->commit($import->refresh());

        $spot = User::query()->holding()->firstOrFail();

        $this->assertSame(ActivationCode::hash('CODE-ALPHA'), $spot->activation_code_hash);
        $this->assertNull($import->rows()->first()->activation_code);
        // Nowhere in the row either.
        $this->assertStringNotContainsString('CODE-ALPHA', json_encode($import->rows()->first()->toArray()));
    }

    public function test_a_committed_spot_is_not_a_member(): void
    {
        $import = $this->stage($this->header() . "A-1,CODE-ALPHA,,\n");
        $this->validate($import);
        $this->linkTop($import, 'A-1');
        $this->commit($import->refresh());

        $spot = User::query()->holding()->firstOrFail();

        $this->assertTrue($spot->isHolding());
        $this->assertFalse((bool) $spot->is_active);
        $this->assertNull($spot->password);
        $this->assertNull($spot->email);
        $this->assertNull($spot->phone);

        // Its only label is the partner's own identifier, because that is the
        // only thing about it we were given.
        $this->assertSame('Spot A-1', $spot->name);
    }

    public function test_a_sponsor_outside_the_file_falls_back_without_orphaning_the_enrollment_tree(): void
    {
        // iHub's export names a sponsor on 4,581 rows that is not in the file,
        // because they track recruitment across a system wider than the slice
        // they sent us. A-3 is one of those rows.
        $import = $this->stage($this->header()
            . "A-1,CODE-ALPHA,,\n"
            . "A-2,CODE-BETA,A-1,A-1\n"
            . "A-3,CODE-GAMMA,A-2,SOMEBODY-ELSE\n");

        $this->validate($import);
        $this->linkTop($import, 'A-1');
        $this->validate($import->refresh());
        $this->commit($import->refresh());

        $spots = User::query()->holding()->get()->keyBy('external_user_id');

        // The position above becomes the sponsor — that part always worked.
        $this->assertSame($spots['A-2']->id, $spots['A-3']->sponsor_id);

        // What did not: the depth pass treated an unresolvable sponsor as the
        // top of an enrollment chain while the commit fell back to the position
        // above, so A-3's enrollment path was built before A-2 had one and A-3
        // came out as a root of its own tree, upline and all.
        $this->assertSame(
            "{$this->founder->enrollment_path}.{$spots['A-1']->id}.{$spots['A-2']->id}.{$spots['A-3']->id}",
            $spots['A-3']->enrollment_path,
        );

        $this->assertSame(
            'A-2',
            $import->rows()->where('external_user_id', 'A-3')->value('effective_sponsor_id'),
        );
    }

    public function test_a_sponsor_the_file_does_contain_is_honoured_over_the_parent(): void
    {
        // Placement and enrollment genuinely diverge here: A-3 sits under A-2
        // but was recruited by A-1.
        $import = $this->stage($this->header()
            . "A-1,CODE-ALPHA,,\n"
            . "A-2,CODE-BETA,A-1,A-1\n"
            . "A-3,CODE-GAMMA,A-2,A-1\n");

        $this->validate($import);
        $this->linkTop($import, 'A-1');
        $this->validate($import->refresh());
        $this->commit($import->refresh());

        $spots = User::query()->holding()->get()->keyBy('external_user_id');

        $this->assertSame($spots['A-2']->id, $spots['A-3']->placement_parent_id);
        $this->assertSame($spots['A-1']->id, $spots['A-3']->sponsor_id);

        $this->assertSame(
            "{$this->founder->enrollment_path}.{$spots['A-1']->id}.{$spots['A-3']->id}",
            $spots['A-3']->enrollment_path,
        );
    }

    public function test_committing_twice_is_refused(): void
    {
        $import = $this->stage($this->header() . "A-1,CODE-ALPHA,,\n");
        $this->validate($import);
        $this->linkTop($import, 'A-1');
        $this->commit($import->refresh());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already been committed/');

        $this->commit($import->refresh());
    }
}
