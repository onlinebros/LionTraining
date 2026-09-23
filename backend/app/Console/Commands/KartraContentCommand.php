<?php

namespace App\Console\Commands;

use App\Services\KartraContentService;
use Illuminate\Console\Command;

class KartraContentCommand extends Command
{
    protected $signature = 'kartra:content
                            {--json= : Path to kartra-page-content.json (default: scripts/kartra-page-content.json)}
                            {--no-download : Import content metadata only, skip file downloads}
                            {--download-only : Skip JSON import, only download pending files}
                            {--url= : Portal URL (default: KARTRA_PORTAL_URL)}
                            {--email= : Login email (default: KARTRA_EMAIL)}
                            {--password= : Login password (default: KARTRA_PASSWORD)}';

    protected $description = 'Import lesson page content and download attached files from Kartra';

    public function handle(): int
    {
        $jsonPath    = $this->option('json') ?: base_path('../scripts/kartra-page-content.json');
        $downloadOnly = $this->option('download-only');
        $noDownload  = $this->option('no-download');

        // Credentials from the environment; see KartraImportCommand for why the
        // literals that used to sit in this signature had to go.
        $service = new KartraContentService(
            $this->option('url') ?: (string) config('services.kartra.url'),
            $this->option('email') ?: (string) config('services.kartra.email'),
            $this->option('password') ?: (string) config('services.kartra.password'),
        );

        // ── Import JSON content ────────────────────────────────────────────
        if (!$downloadOnly) {
            if (!file_exists($jsonPath)) {
                $this->error("Content JSON not found: {$jsonPath}");
                $this->line('Run first: node scripts/kartra-scrape-content.js');
                return self::FAILURE;
            }

            $pages = json_decode(file_get_contents($jsonPath), true);
            if (!is_array($pages)) {
                $this->error('Invalid JSON.');
                return self::FAILURE;
            }

            $this->info("Importing page content for " . count($pages) . " lessons…");
            $result = $service->importContent($pages);

            $this->info("  Updated : {$result['updated']} lessons");
            $this->info("  Files   : {$result['files_created']} new file records created");
            foreach ($result['errors'] as $e) $this->warn("  ! $e");
        }

        // ── Download files ─────────────────────────────────────────────────
        if (!$noDownload) {
            $pending = \App\Models\KartraFile::where('status', 'pending')->count();
            if ($pending === 0) {
                $this->info('No pending file downloads.');
                return self::SUCCESS;
            }

            $this->info("Downloading {$pending} files…");
            $result = $service->downloadPendingFiles();

            $this->info("  Downloaded : {$result['count']} files");
            foreach ($result['errors'] as $e) $this->warn("  ! $e");
        }

        return self::SUCCESS;
    }
}
