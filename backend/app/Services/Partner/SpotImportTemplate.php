<?php

namespace App\Services\Partner;

/**
 * The CSV contract with a partner company — one definition, three consumers.
 *
 * The parser reads columns from here, the admin template download is generated
 * from here, and `resources/templates/partner-spot-import-template.csv` is
 * written from here by `php artisan partners:import-template`. A test asserts
 * the checked-in file still matches, because the failure mode of letting them
 * drift is a partner filling in last quarter's columns and an import that
 * silently drops a column of parent ids.
 *
 * ── No personal data crosses this boundary ───────────────────────────────────
 *
 * Four columns: two identifiers, a code, and the shape of the tree. No names, no
 * email addresses, no phone numbers, no addresses. An imported position is a
 * place in a structure, and a place in a structure does not need to know who is
 * coming to stand in it — the person supplies their own details when they claim,
 * to us, having chosen to.
 *
 * This is not only a policy. It removes a whole category of problem: we cannot
 * leak a list we never held, we cannot show an upline the name of somebody who
 * has not joined us, we cannot email a stale address somebody never gave us, and
 * a partner company can hand this file over without a data-processing argument.
 *
 * Because that only holds if it is enforced rather than requested, the parser
 * discards the values in any column not listed here and reports the column names
 * back so an admin can tell the partner what was thrown away. Adding a column
 * that carries personal data to this list undoes all of the above.
 *
 * ── Header matching ──────────────────────────────────────────────────────────
 *
 * Deliberately forgiving: case, spaces, hyphens and underscores are all
 * normalised away, and each column carries aliases for the names partner
 * companies actually use. The file arrives from somebody else's export, and
 * rejecting it over "User ID" vs "user_id" costs a day per round trip for
 * nothing.
 */
class SpotImportTemplate
{
    /**
     * Columns in the order they appear in the template.
     *
     * @var array<string, array{required:bool, aliases:list<string>, help:string, sample:list<string>}>
     */
    public const COLUMNS = [
        'external_user_id' => [
            'required' => true,
            'aliases'  => ['userid', 'user', 'memberid', 'member', 'membernumber', 'id', 'distributorid', 'iboid', 'ibonumber'],
            'help'     => 'The ID this member already has with the partner company. Unique within the file. This is half of what they type on the claim page, so it must be the ID they actually know themselves by — not an internal database key. It is also the only label we will ever have for the position until somebody claims it, so an admin has to be able to match it against your own export.',
            'sample'   => ['ACME-1001', 'ACME-1002', 'ACME-1003', 'ACME-1004', 'ACME-1005'],
        ],
        'activation_code' => [
            'required' => true,
            'aliases'  => ['code', 'activation', 'activationkey', 'accesscode', 'claimcode', 'pin'],
            'help'     => 'The one-time code that proves this position is theirs. Unique across the whole file. Treat this column as a password list: send the file over something that is not email, and expect us to destroy our copy after the import commits.',
            'sample'   => ['7Q4K-2XB9', 'M8TZ-6LP1', 'C3VH-9RD5', 'K2NW-4JY8', 'B6SF-1QM7'],
        ],
        'external_parent_id' => [
            'required' => false,
            'aliases'  => ['parentid', 'parent', 'uplineid', 'upline', 'placementid', 'placedunder', 'sponsorid_placement'],
            'help'     => 'The external_user_id of the position directly above this one. Leave blank ONLY for the top of a leg — those rows get connected to an existing Quantum partner by hand, on screen, before the import runs. Every non-blank value must appear as an external_user_id somewhere in this same file.',
            'sample'   => ['', 'ACME-1001', 'ACME-1001', 'ACME-1002', 'ACME-1002'],
        ],
        'external_sponsor_id' => [
            'required' => false,
            'aliases'  => ['sponsor', 'sponsorid', 'enrollerid', 'enroller', 'recruiterid'],
            'help'     => 'Who recruited this member, if you track that separately from position. Leave the whole column blank if recruitment and placement are the same thing in your system — we will use the parent.',
            'sample'   => ['', 'ACME-1001', 'ACME-1001', 'ACME-1002', 'ACME-1001'],
        ],
    ];

    /** How many sample rows the template carries. */
    public const SAMPLE_ROWS = 5;

    /** @return list<string> */
    public static function headers(): array
    {
        return array_keys(self::COLUMNS);
    }

    /** @return list<string> */
    public static function requiredHeaders(): array
    {
        return array_keys(array_filter(self::COLUMNS, fn (array $c) => $c['required']));
    }

    /**
     * Map a header as written in somebody's export onto our column name.
     *
     * Returns null for a column we do not recognise; the parser keeps those in
     * the row's `raw` payload rather than discarding them.
     */
    public static function resolveHeader(string $header): ?string
    {
        $needle = self::normalise($header);

        if ($needle === '') {
            return null;
        }

        foreach (self::COLUMNS as $name => $column) {
            if ($needle === self::normalise($name)) {
                return $name;
            }

            foreach ($column['aliases'] as $alias) {
                if ($needle === self::normalise($alias)) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * Strip everything a spreadsheet export varies on.
     *
     * Also strips a UTF-8 BOM, which Excel writes onto the first header and
     * which otherwise makes column one — always the required id — look like a
     * column we have never heard of.
     */
    public static function normalise(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;

        return strtolower(preg_replace('/[^a-z0-9]/i', '', $header) ?? '');
    }

    /**
     * The template file's contents: header row, then sample rows.
     *
     * Sample rows are included rather than shipping a bare header because the
     * parent column is the one people get wrong, and one worked example of a
     * two-level leg explains it better than the help text does. They are
     * obviously fake — every id is prefixed ACME- — and the importer reports
     * how many rows it found, so a file submitted with the samples still in it
     * is caught at review rather than at commit.
     */
    public static function csv(bool $withSamples = true): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, self::headers(), escape: '');

        if ($withSamples) {
            for ($i = 0; $i < self::SAMPLE_ROWS; $i++) {
                $row = [];

                foreach (self::COLUMNS as $column) {
                    $row[] = $column['sample'][$i] ?? '';
                }

                fputcsv($handle, $row, escape: '');
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** The column guide, as Markdown, for handing to the partner company. */
    public static function documentation(): string
    {
        $lines = [
            '# Partner spot import — CSV format',
            '',
            'One row per position. The file describes a tree: `external_user_id` is the node,',
            '`external_parent_id` is the edge to the node above it.',
            '',
            '## Please do not send us personal data',
            '',
            'These four columns are the whole file. **No names, no email addresses, no phone',
            'numbers, no addresses, no join dates.** We are importing positions in a structure,',
            'not people — each member supplies their own details to us directly when they claim',
            'their position, and until then all we need to know about a position is where it',
            'sits and which code opens it.',
            '',
            'Any other column in the file is ignored: the values are discarded on read, not',
            'stored, and we report the column names back so you know what we dropped. If your',
            'export cannot omit those columns, send it anyway — but it is better for both of us',
            'if the data never leaves your system.',
            '',
            '## Handling the file',
            '',
            '`activation_code` is a column of live credentials. Anyone holding a user ID and its',
            'code can take ownership of that position and everything below it, so send the file',
            'over something other than plain email, and expect us to destroy our copy once the',
            'import is committed. We store only a hash of each code from that point on — we',
            'cannot read them back, and neither can anyone who gets into our database.',
            '',
            '## Linking your members straight to the claim page',
            '',
            'Your claim page is at `https://app.q3.life/partner/<your slug>`. You can put both',
            'credentials in the link so your member arrives at a form that is already filled in:',
            '',
            '```',
            'https://app.q3.life/partner/<your slug>?uid=<external_user_id>&code=<activation_code>',
            '```',
            '',
            'Parameter names are matched loosely: case, underscores and hyphens are ignored, so',
            '`activation_code`, `activate_code`, `activationCode` and `ACTIVATE-CODE` are all the',
            'same parameter. For the ID we accept `uid`, `user_id`, `member_id` and similar; for',
            'the code, `code`, `activation_code`, `activate_code`, `access_code` and similar.',
            'Anything we do not recognise — tracking parameters and the like — is ignored.',
            '',
            'Both values are URL-encoded as usual. The page immediately redirects to the clean',
            'URL, so the code is out of the address bar before anything renders — it is not in',
            'what they screenshot, bookmark or forward on.',
            '',
            'Nothing is claimed by following the link: it only fills the boxes. A link scanner or',
            'prefetcher cannot take somebody\'s position, and cannot use up an attempt.',
            '',
            'Be aware that the code is in a URL, so it will appear in your mailer\'s click logs and',
            'in our web server access log. The code is single-use and the position locks after five',
            'wrong attempts, so a code recovered from a log after its owner has claimed is worthless',
            '— but if that is still not acceptable to you, tell us and we will hand out short-lived',
            'opaque tokens for the links instead.',
            '',
            '## Columns',
            '',
            '| Column | Required | Notes |',
            '| --- | --- | --- |',
        ];

        foreach (self::COLUMNS as $name => $column) {
            $help = str_replace('|', '\\|', $column['help']);
            $lines[] = "| `{$name}` | " . ($column['required'] ? 'Yes' : 'No') . " | {$help} |";
        }

        return implode("\n", $lines) . "\n";
    }
}
