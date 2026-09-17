<?php

namespace Tests\Feature\Partner;

use App\Console\Commands\PartnerImportTemplate;
use App\Services\Partner\SpotImportTemplate;
use Tests\TestCase;

/**
 * The template we hand a partner company has to be the one the parser reads.
 *
 * A partner filling in a stale template is an import that silently drops a
 * column — most damagingly the parent column, which would turn a whole
 * organisation into a flat list of roots.
 */
class ImportTemplateTest extends TestCase
{
    public function test_the_checked_in_template_matches_the_code(): void
    {
        $this->artisan('partners:import-template --check')->assertSuccessful();
    }

    public function test_the_template_carries_every_column_the_parser_knows(): void
    {
        $header = str_getcsv(strtok(SpotImportTemplate::csv(), "\n"));

        $this->assertSame(SpotImportTemplate::headers(), $header);
    }

    public function test_the_sample_rows_describe_a_tree_that_would_validate(): void
    {
        $lines = array_filter(explode("\n", SpotImportTemplate::csv()));
        array_shift($lines);

        $ids = $parents = [];
        $legTops = 0;

        foreach ($lines as $line) {
            $fields = str_getcsv($line);
            $ids[] = $fields[0];

            if ($fields[2] === '') {
                $legTops++;
            } else {
                $parents[] = $fields[2];
            }
        }

        // The two rules an admin has to explain to a partner company, shown in
        // the file rather than only described in the guide: ids are unique,
        // every parent named is a row here, and a blank parent means a leg top.
        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertEmpty(array_diff($parents, $ids));
        $this->assertSame(1, $legTops);
    }

    public function test_headers_are_matched_past_case_spacing_and_the_partners_own_names(): void
    {
        foreach (['User ID', 'user_id', 'MemberNumber', 'Distributor ID'] as $spelling) {
            $this->assertSame('external_user_id', SpotImportTemplate::resolveHeader($spelling), $spelling);
        }

        foreach (['Upline ID', 'placement_id', 'Placed Under'] as $spelling) {
            $this->assertSame('external_parent_id', SpotImportTemplate::resolveHeader($spelling), $spelling);
        }

        $this->assertNull(SpotImportTemplate::resolveHeader('their_internal_key'));
    }

    public function test_the_template_asks_for_no_personal_data(): void
    {
        // The guard on the decision, not just a description of it. Adding a
        // column here that carries personal data undoes the whole reason the
        // import holds none: we cannot leak a list we never received, and a
        // partner company can hand this file over without an argument about it.
        $forbidden = [
            'first_name', 'last_name', 'name', 'email', 'phone', 'mobile',
            'address', 'address_line1', 'city', 'state', 'postal_code', 'zip',
            'country', 'dob', 'date_of_birth', 'ssn', 'tax_id', 'joined_at',
        ];

        $this->assertSame(
            [],
            array_intersect($forbidden, SpotImportTemplate::headers()),
            'The import template must not ask a partner company for personal data.',
        );

        $this->assertSame(
            ['external_user_id', 'activation_code', 'external_parent_id', 'external_sponsor_id'],
            SpotImportTemplate::headers(),
        );
    }

    public function test_the_guide_tells_the_partner_not_to_send_personal_data(): void
    {
        // The file goes to somebody outside this company, and it is the only
        // instruction they get.
        $doc = SpotImportTemplate::documentation();

        $this->assertStringContainsString('do not send us personal data', strtolower($doc));
        $this->assertStringContainsString('discarded on read', $doc);
    }

    public function test_the_column_guide_lists_every_column(): void
    {
        $doc = SpotImportTemplate::documentation();

        foreach (SpotImportTemplate::headers() as $column) {
            $this->assertStringContainsString("`{$column}`", $doc);
        }
    }

    public function test_the_generator_writes_where_the_repo_expects(): void
    {
        $this->assertFileExists(base_path(PartnerImportTemplate::CSV_PATH));
        $this->assertFileExists(base_path(PartnerImportTemplate::DOC_PATH));
    }
}
