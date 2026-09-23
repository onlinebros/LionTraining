<?php

namespace App\Console\Commands;

use App\Services\KartraImportService;
use Illuminate\Console\Command;

class KartraImportCommand extends Command
{
    // Credentials come from the environment (KARTRA_PORTAL_URL, KARTRA_EMAIL,
    // KARTRA_PASSWORD), not from defaults baked into the signature. They were
    // literal values here, which put a working portal login in the repository
    // and printed the email into every console log.
    protected $signature = 'kartra:import
                            {--url= : Kartra portal URL (default: KARTRA_PORTAL_URL)}
                            {--email= : Login email (default: KARTRA_EMAIL)}
                            {--password= : Login password (default: KARTRA_PASSWORD)}
                            {--no-download : Skip video downloads, only scrape structure}
                            {--json= : Path to a JSON file to import instead of scraping live}';

    protected $description = 'Scrape the Kartra portal content structure and download videos';

    public function handle(): int
    {
        $this->info('Starting Kartra content import…');

        $jsonFile = $this->option('json');

        if ($jsonFile) {
            return $this->importFromJson($jsonFile);
        }

        return $this->importLive();
    }

    private function importLive(): int
    {
        $url      = $this->option('url') ?: config('services.kartra.url');
        $email    = $this->option('email') ?: config('services.kartra.email');
        $password = $this->option('password') ?: config('services.kartra.password');

        if (!$url || !$email || !$password) {
            $this->error('Kartra portal credentials are not set.');
            $this->line('Set KARTRA_PORTAL_URL, KARTRA_EMAIL and KARTRA_PASSWORD, or pass --url/--email/--password.');

            return self::FAILURE;
        }

        $this->line("Portal : $url");

        $service  = new KartraImportService($url, $email, $password);
        $download = !$this->option('no-download');

        $this->info($download ? 'Will download videos after scraping.' : 'Scrape-only mode (no downloads).');

        $result = $service->run($download);

        $this->info("Imported items : {$result['imported']}");
        $this->info("Videos downloaded : {$result['downloaded']}");

        if (!empty($result['errors'])) {
            $this->warn('Errors encountered:');
            foreach ($result['errors'] as $err) {
                $this->line("  - $err");
            }
        }

        if ($result['imported'] === 0) {
            $this->warn('');
            $this->warn('No items were imported. The Kartra portal may require JavaScript rendering.');
            $this->warn('Options:');
            $this->warn('  1. Export content manually and import via --json');
            $this->warn('  2. Use a headless browser (Puppeteer/Playwright) to extract the content JSON');
            $this->warn('  3. Check storage/logs/laravel.log for detail');
            $this->warn('');
            $this->info('JSON import format:');
            $this->line('  php artisan kartra:import --json=/path/to/content.json');
            $this->line('');
            $this->line('  content.json structure:');
            $this->line('  [');
            $this->line('    { "type": "module", "title": "Module 1", "order": 1, "children": [');
            $this->line('      { "type": "lesson", "title": "Lesson 1", "video_url": "https://...", "order": 1 }');
            $this->line('    ]}');
            $this->line('  ]');
        }

        return self::SUCCESS;
    }

    private function importFromJson(string $jsonFile): int
    {
        if (!file_exists($jsonFile)) {
            $this->error("JSON file not found: $jsonFile");
            return self::FAILURE;
        }

        $content = file_get_contents($jsonFile);
        $data    = json_decode($content, true);

        if (!is_array($data)) {
            $this->error('Invalid JSON: must be an array of module/lesson objects.');
            return self::FAILURE;
        }

        $service = new KartraImportService('', '', '');
        $count   = $service->importFromJson($data);

        $this->info("Imported $count items from JSON.");

        // --no-download used to be ignored on this path, so the command asked
        // the question anyway and blocked forever when run unattended.
        if ($this->option('no-download')) {
            $this->line('Scrape-only mode: videos not downloaded.');
            $this->line('Attach media already on disk with: php artisan training:adopt-media');

            return self::SUCCESS;
        }

        if (!$this->input->isInteractive() || $this->confirm('Download videos for imported items now?', true)) {
            $result = $service->downloadPendingVideos();
            $this->info("Downloaded {$result['count']} videos.");
            foreach ($result['errors'] as $err) {
                $this->warn("  - $err");
            }
        }

        return self::SUCCESS;
    }
}
