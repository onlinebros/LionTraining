<?php

namespace App\Services;

use App\Models\KartraImport;
use App\Models\VideoAsset;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Scrapes the Kartra "Lion" membership portal, records the content hierarchy
 * in kartra_imports, and downloads video files to local storage.
 *
 * NOTE: Kartra portals are JavaScript-heavy SPAs. This service does its best
 * with plain HTTP requests, but complex portals may require a headless browser
 * (e.g. Laravel Dusk / Puppeteer) to fully render. In that case use the
 * importFromJson() method to supply a manually-exported content map.
 */
class KartraImportService
{
    private string $portalUrl;
    private string $email;
    private string $password;
    private ?string $sessionCookie = null;

    public function __construct(string $portalUrl, string $email, string $password)
    {
        $this->portalUrl = rtrim($portalUrl, '/');
        $this->email     = $email;
        $this->password  = $password;
    }

    // ── Public entry points ────────────────────────────────────────────────────

    /**
     * Full automated import: login → scrape → download videos.
     * Returns an array of ['imported' => int, 'downloaded' => int, 'errors' => array].
     */
    public function run(bool $downloadVideos = true): array
    {
        $result = ['imported' => 0, 'downloaded' => 0, 'errors' => []];

        try {
            $this->login();
        } catch (\Throwable $e) {
            $result['errors'][] = 'Login failed: ' . $e->getMessage();
            Log::error('[KartraImport] Login failed', ['error' => $e->getMessage()]);
            return $result;
        }

        try {
            $items = $this->scrapePortal();
            foreach ($items as $item) {
                $this->saveItem($item, null);
                $result['imported']++;
            }
        } catch (\Throwable $e) {
            $result['errors'][] = 'Scraping failed: ' . $e->getMessage();
            Log::error('[KartraImport] Scrape failed', ['error' => $e->getMessage()]);
        }

        if ($downloadVideos) {
            $downloads = $this->downloadPendingVideos();
            $result['downloaded'] = $downloads['count'];
            $result['errors'] = array_merge($result['errors'], $downloads['errors']);
        }

        return $result;
    }

    /**
     * Import from a manually-constructed JSON array.
     * Expected format:
     * [
     *   { "type": "module", "title": "...", "description": "...", "order": 1,
     *     "children": [
     *       { "type": "lesson", "title": "...", "video_url": "...", "order": 1 }
     *     ]
     *   }
     * ]
     */
    public function importFromJson(array $items, ?int $parentId = null): int
    {
        $count = 0;
        foreach ($items as $i => $item) {
            $import = KartraImport::create([
                'kartra_type'        => $item['type'] ?? 'lesson',
                'kartra_id'          => $item['id'] ?? null,
                'kartra_title'       => $item['title'] ?? 'Untitled',
                'kartra_description' => $item['description'] ?? null,
                'kartra_url'         => $item['url'] ?? null,
                'kartra_video_url'   => $item['video_url'] ?? null,
                'kartra_thumbnail_url' => $item['thumbnail_url'] ?? null,
                'kartra_order'       => $item['order'] ?? $i,
                'parent_id'          => $parentId,
                'status'             => 'discovered',
                'raw_data'           => $item,
            ]);
            $count++;

            if (!empty($item['children'])) {
                $count += $this->importFromJson($item['children'], $import->id);
            }
        }
        return $count;
    }

    /**
     * Download all kartra_imports that have a kartra_video_url but no video_asset yet.
     */
    public function downloadPendingVideos(): array
    {
        $result = ['count' => 0, 'errors' => []];

        $pending = KartraImport::whereNotNull('kartra_video_url')
            ->whereNull('video_asset_id')
            ->whereIn('status', ['discovered', 'failed'])
            ->get();

        foreach ($pending as $import) {
            try {
                $asset = $this->downloadVideo($import);
                $import->update(['video_asset_id' => $asset->id, 'status' => 'downloaded']);
                $result['count']++;
            } catch (\Throwable $e) {
                $import->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
                $result['errors'][] = "#{$import->id} {$import->kartra_title}: " . $e->getMessage();
                Log::error('[KartraImport] Video download failed', [
                    'import_id' => $import->id,
                    'url'       => $import->kartra_video_url,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function login(): void
    {
        // Fetch the portal page to discover the login endpoint
        $response = Http::timeout(30)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; LionImporter/1.0)'])
            ->get($this->portalUrl);

        // Kartra login form submissions typically go to /login endpoint
        $loginUrl = $this->resolveLoginUrl($response->body());

        $loginResponse = Http::timeout(30)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; LionImporter/1.0)'])
            ->asForm()
            ->post($loginUrl, [
                'member_email'    => $this->email,
                'member_password' => $this->password,
            ]);

        // Capture session cookie from response or redirect chain
        $cookies = $loginResponse->cookies();
        $cookieParts = [];
        foreach ($cookies as $cookie) {
            $cookieParts[] = $cookie->getName() . '=' . $cookie->getValue();
        }
        $this->sessionCookie = implode('; ', $cookieParts);

        if ($loginResponse->failed() || str_contains($loginResponse->body(), 'Invalid email or password')) {
            throw new \RuntimeException('Login rejected by Kartra.');
        }

        Log::info('[KartraImport] Login successful.');
    }

    private function resolveLoginUrl(string $html): string
    {
        // Look for action attribute in login form
        if (preg_match('/action=["\']([^"\']*login[^"\']*)["\']/', $html, $m)) {
            $action = html_entity_decode($m[1]);
            if (str_starts_with($action, 'http')) return $action;
            $base = parse_url($this->portalUrl, PHP_URL_SCHEME) . '://' . parse_url($this->portalUrl, PHP_URL_HOST);
            return $base . '/' . ltrim($action, '/');
        }

        // Default Kartra login endpoint pattern
        $host = parse_url($this->portalUrl, PHP_URL_SCHEME) . '://' . parse_url($this->portalUrl, PHP_URL_HOST);
        return $host . '/login';
    }

    private function scrapePortal(): array
    {
        $headers = ['User-Agent' => 'Mozilla/5.0 (compatible; LionImporter/1.0)'];
        if ($this->sessionCookie) {
            $headers['Cookie'] = $this->sessionCookie;
        }

        $response = Http::timeout(30)->withHeaders($headers)->get($this->portalUrl);

        if ($response->failed()) {
            throw new \RuntimeException('Could not fetch portal page: HTTP ' . $response->status());
        }

        return $this->parsePortalHtml($response->body());
    }

    private function parsePortalHtml(string $html): array
    {
        $items = [];

        // Try to extract structured data from common Kartra portal patterns
        // Kartra wraps modules in data attributes or JSON blobs

        // 1. Look for inline JSON data (Kartra injects portal data as JS variables)
        if (preg_match('/portal_data\s*=\s*(\{.+?\});/s', $html, $m)) {
            $data = json_decode($m[1], true);
            if ($data) {
                return $this->parseKartraPortalData($data);
            }
        }

        // 2. Look for JSON-LD or application/json script blocks
        preg_match_all('/<script[^>]*type=["\']application\/json["\'][^>]*>(.+?)<\/script>/si', $html, $matches);
        foreach ($matches[1] as $json) {
            $data = json_decode(trim($json), true);
            if (is_array($data) && (isset($data['modules']) || isset($data['lessons']))) {
                return $this->parseKartraPortalData($data);
            }
        }

        // 3. DOM-based scraping for common Kartra HTML patterns
        $items = $this->scrapePortalDom($html);

        if (empty($items)) {
            Log::warning('[KartraImport] Portal scrape yielded no items. Portal may require JavaScript rendering.');
        }

        return $items;
    }

    private function parseKartraPortalData(array $data): array
    {
        $items = [];
        $modules = $data['modules'] ?? $data['categories'] ?? [];

        foreach ($modules as $i => $mod) {
            $item = [
                'type'        => 'module',
                'id'          => $mod['id'] ?? null,
                'title'       => $mod['title'] ?? $mod['name'] ?? 'Module ' . ($i + 1),
                'description' => $mod['description'] ?? null,
                'url'         => $mod['url'] ?? null,
                'order'       => $i,
                'children'    => [],
            ];

            $lessons = $mod['lessons'] ?? $mod['pages'] ?? [];
            foreach ($lessons as $j => $lesson) {
                $item['children'][] = [
                    'type'          => 'lesson',
                    'id'            => $lesson['id'] ?? null,
                    'title'         => $lesson['title'] ?? $lesson['name'] ?? 'Lesson ' . ($j + 1),
                    'description'   => $lesson['description'] ?? null,
                    'url'           => $lesson['url'] ?? null,
                    'video_url'     => $lesson['video_url'] ?? $lesson['media_url'] ?? null,
                    'thumbnail_url' => $lesson['thumbnail'] ?? $lesson['image'] ?? null,
                    'order'         => $j,
                ];
            }

            $items[] = $item;
        }

        return $items;
    }

    private function scrapePortalDom(string $html): array
    {
        $items = [];
        $dom   = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR);
        $xpath = new \DOMXPath($dom);

        // Kartra portal: look for module containers (common class patterns)
        $moduleNodes = $xpath->query('//*[contains(@class,"module") or contains(@class,"category") or contains(@class,"section")]');

        foreach ($moduleNodes as $i => $node) {
            $title = $this->nodeText($xpath, './/h1|.//h2|.//h3|.//h4', $node);
            if (!$title) continue;

            $item = [
                'type'     => 'module',
                'title'    => $title,
                'order'    => $i,
                'children' => [],
            ];

            // Look for lesson links within this module
            $lessonNodes = $xpath->query('.//*[contains(@class,"lesson") or contains(@class,"content") or contains(@class,"item")]', $node);
            foreach ($lessonNodes as $j => $lessonNode) {
                $lTitle    = $this->nodeText($xpath, './/h2|.//h3|.//h4|.//h5|.//a', $lessonNode);
                $videoUrl  = $this->extractVideoUrl($xpath, $lessonNode);
                $lessonUrl = $this->extractHref($xpath, $lessonNode);

                if (!$lTitle) continue;

                $item['children'][] = [
                    'type'      => 'lesson',
                    'title'     => $lTitle,
                    'url'       => $lessonUrl,
                    'video_url' => $videoUrl,
                    'order'     => $j,
                ];
            }

            $items[] = $item;
        }

        // Also scrape direct video/iframe embeds if no module structure found
        if (empty($items)) {
            $iframes = $xpath->query('//iframe[contains(@src,"vimeo") or contains(@src,"youtube") or contains(@src,"wistia")]');
            foreach ($iframes as $i => $iframe) {
                $src   = $iframe->getAttribute('src');
                $title = $iframe->getAttribute('title') ?: 'Video ' . ($i + 1);
                $items[] = [
                    'type'      => 'lesson',
                    'title'     => $title,
                    'video_url' => $src,
                    'order'     => $i,
                ];
            }
        }

        return $items;
    }

    private function nodeText(\DOMXPath $xpath, string $query, \DOMNode $context): string
    {
        $nodes = $xpath->query($query, $context);
        if ($nodes && $nodes->length > 0) {
            return trim($nodes->item(0)->textContent);
        }
        return '';
    }

    private function extractVideoUrl(\DOMXPath $xpath, \DOMNode $context): ?string
    {
        // Look for iframes or data-video attributes
        $iframe = $xpath->query('.//iframe[contains(@src,"vimeo") or contains(@src,"youtube") or contains(@src,"wistia")]', $context);
        if ($iframe && $iframe->length > 0) {
            return $iframe->item(0)->getAttribute('src');
        }
        $vid = $xpath->query('.//*[@data-video-url or @data-src]', $context);
        if ($vid && $vid->length > 0) {
            return $vid->item(0)->getAttribute('data-video-url') ?: $vid->item(0)->getAttribute('data-src');
        }
        return null;
    }

    private function extractHref(\DOMXPath $xpath, \DOMNode $context): ?string
    {
        $a = $xpath->query('.//a[@href]', $context);
        if ($a && $a->length > 0) {
            return $a->item(0)->getAttribute('href') ?: null;
        }
        return null;
    }

    private function saveItem(array $item, ?int $parentId): void
    {
        $import = KartraImport::create([
            'kartra_type'          => $item['type'] ?? 'lesson',
            'kartra_id'            => $item['id'] ?? null,
            'kartra_title'         => $item['title'] ?? 'Untitled',
            'kartra_description'   => $item['description'] ?? null,
            'kartra_url'           => $item['url'] ?? null,
            'kartra_video_url'     => $item['video_url'] ?? null,
            'kartra_thumbnail_url' => $item['thumbnail_url'] ?? null,
            'kartra_order'         => $item['order'] ?? 0,
            'parent_id'            => $parentId,
            'status'               => 'discovered',
            'raw_data'             => $item,
        ]);

        foreach ($item['children'] ?? [] as $child) {
            $this->saveItem($child, $import->id);
        }
    }

    private function downloadVideo(KartraImport $import): VideoAsset
    {
        $url = $import->kartra_video_url;

        $import->update(['status' => 'downloading']);

        // Resolve the actual downloadable URL (handle Vimeo/YouTube by extracting direct links)
        $downloadUrl = $this->resolveDownloadUrl($url);

        $headers = ['User-Agent' => 'Mozilla/5.0 (compatible; LionImporter/1.0)'];
        if ($this->sessionCookie) $headers['Cookie'] = $this->sessionCookie;

        // Stream download to avoid memory issues with large files
        $directory = 'kartra-videos';
        $ext       = $this->guessExtension($downloadUrl);
        $filename  = Str::slug($import->kartra_title) . '-' . $import->id . $ext;
        $localPath = $directory . '/' . $filename;

        Storage::disk('local')->makeDirectory($directory);
        $absolutePath = Storage::disk('local')->path($localPath);

        $response = Http::timeout(300)
            ->withHeaders($headers)
            ->sink($absolutePath)
            ->get($downloadUrl);

        if ($response->failed()) {
            throw new \RuntimeException("Download failed with HTTP " . $response->status());
        }

        $fileSize = Storage::disk('local')->size($localPath);
        $mime     = mime_content_type($absolutePath) ?: 'video/mp4';

        return VideoAsset::create([
            'title'          => $import->kartra_title,
            'description'    => $import->kartra_description,
            'source'         => 'kartra',
            'source_url'     => $url,
            'source_id'      => $import->kartra_id,
            'local_path'     => $localPath,
            'local_filename' => $filename,
            'file_size'      => $fileSize,
            'mime_type'      => $mime,
            'vimeo_status'   => 'pending',
        ]);
    }

    private function resolveDownloadUrl(string $url): string
    {
        // Vimeo and YouTube direct downloads require special handling.
        // For now we pass the URL through and let yt-dlp handle it if the
        // admin chooses the yt-dlp download path (see KartraImportCommand).
        // For direct CDN URLs (e.g., from Kartra's own hosting) this works as-is.
        return $url;
    }

    private function guessExtension(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $ext  = pathinfo($path, PATHINFO_EXTENSION);
        return $ext ? '.' . $ext : '.mp4';
    }
}
