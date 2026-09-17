<?php

namespace App\Console\Commands;

use App\Services\Partner\SpotImportTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Write the CSV template and its column guide out of the code that reads them.
 *
 * The checked-in copies exist so somebody can hand a partner company a file
 * without logging into the admin. They are generated rather than maintained,
 * and PartnerImportTemplateTest fails if they fall behind — a partner filling
 * in a stale template is an import that silently drops a column.
 */
class PartnerImportTemplate extends Command
{
    protected $signature = 'partners:import-template
                            {--check : Exit non-zero if the checked-in files are out of date}';

    protected $description = 'Regenerate the partner spot import CSV template and its column guide';

    public const CSV_PATH = 'resources/templates/partner-spot-import-template.csv';
    public const DOC_PATH = 'resources/templates/partner-spot-import-template.md';

    public function handle(): int
    {
        $files = [
            base_path(self::CSV_PATH) => SpotImportTemplate::csv(),
            base_path(self::DOC_PATH) => SpotImportTemplate::documentation(),
        ];

        $stale = false;

        foreach ($files as $path => $contents) {
            $current = File::exists($path) ? File::get($path) : null;

            if ($current === $contents) {
                $this->line("  <fg=gray>unchanged</> {$path}");
                continue;
            }

            $stale = true;

            if ($this->option('check')) {
                $this->error("Out of date: {$path}");
                continue;
            }

            File::ensureDirectoryExists(dirname($path));
            File::put($path, $contents);
            $this->info("  written  {$path}");
        }

        if ($this->option('check') && $stale) {
            $this->newLine();
            $this->error('Run `php artisan partners:import-template` and commit the result.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
