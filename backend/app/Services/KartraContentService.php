<?php

namespace App\Services;

use App\Models\KartraFile;
use App\Models\KartraImport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Imports page content (text + files) from the JSON produced by
 * scripts/kartra-scrape-content.js, and downloads all attached files.
 */
class KartraContentService
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

    // ── Public entry points ────────────────────────────────────────────────

    /**
     * Import page content from the scraped JSON.
     * Updates existing kartra_imports rows; creates kartra_files records.
     */
    public function importContent(array $pages): array
    {
        $result = ['updated' => 0, 'files_created' => 0, 'errors' => []];

        foreach ($pages as $page) {
            $kartraId = (string) ($page['kartra_id'] ?? '');
            if (!$kartraId) continue;

            $import = KartraImport::where('kartra_id', $kartraId)->first();
            if (!$import) {
                $result['errors'][] = "No kartra_import found for kartra_id={$kartraId}";
                continue;
            }

            // Update content fields
            $import->update([
                'page_title'   => $page['page_title']   ?? null,
                'page_content' => $page['content_text'] ?? null,
                'page_html'    => $page['content_html'] ?? null,
            ]);
            $result['updated']++;

            // Create kartra_files records (skip duplicates by download_id)
            foreach ($page['files'] ?? [] as $file) {
                $dlId = $file['download_id'] ?? null;

                $exists = KartraFile::where('kartra_import_id', $import->id)
                    ->where(function ($q) use ($dlId, $file) {
                        if ($dlId) {
                            $q->where('kartra_download_id', $dlId);
                        } else {
                            $q->where('kartra_download_url', $file['url'] ?? '');
                        }
                    })->exists();

                if ($exists) continue;

                KartraFile::create([
                    'kartra_import_id'   => $import->id,
                    'kartra_download_id' => $dlId,
                    'kartra_download_url' => $file['url'] ?? null,
                    'display_name'       => $file['display_name'] ?? null,
                    'status'             => 'pending',
                ]);
                $result['files_created']++;
            }
        }

        return $result;
    }

    /**
     * Download all pending kartra_files to local storage.
     */
    public function downloadPendingFiles(bool $reauth = true): array
    {
        $result = ['count' => 0, 'errors' => []];

        if ($reauth) {
            try {
                $this->login();
            } catch (\Throwable $e) {
                $result['errors'][] = 'Login failed: ' . $e->getMessage();
                return $result;
            }
        }

        $pending = KartraFile::where('status', 'pending')->get();

        foreach ($pending as $file) {
            try {
                $this->downloadFile($file);
                $result['count']++;
            } catch (\Throwable $e) {
                $file->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
                $result['errors'][] = "File #{$file->id} [{$file->display_name}]: " . $e->getMessage();
                Log::error('[KartraContent] File download failed', [
                    'file_id' => $file->id,
                    'url'     => $file->kartra_download_url,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function login(): void
    {
        $response = Http::timeout(30)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; LionImporter/1.0)'])
            ->get($this->portalUrl);

        $loginUrl = $this->resolveLoginUrl($response->body());

        $loginResponse = Http::timeout(30)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; LionImporter/1.0)'])
            ->asForm()
            ->post($loginUrl, [
                'username' => $this->email,
                'password' => $this->password,
            ]);

        $cookieParts = [];
        foreach ($loginResponse->cookies() as $cookie) {
            $cookieParts[] = $cookie->getName() . '=' . $cookie->getValue();
        }
        $this->sessionCookie = implode('; ', $cookieParts);

        Log::info('[KartraContent] Login attempted', ['cookie_set' => (bool) $this->sessionCookie]);
    }

    private function resolveLoginUrl(string $html): string
    {
        if (preg_match('/action=["\']([^"\']*login[^"\']*)["\']/', $html, $m)) {
            $action = html_entity_decode($m[1]);
            if (str_starts_with($action, 'http')) return $action;
            $base = parse_url($this->portalUrl, PHP_URL_SCHEME) . '://' . parse_url($this->portalUrl, PHP_URL_HOST);
            return $base . '/' . ltrim($action, '/');
        }
        $host = parse_url($this->portalUrl, PHP_URL_SCHEME) . '://' . parse_url($this->portalUrl, PHP_URL_HOST);
        return $host . '/login';
    }

    private function downloadFile(KartraFile $file): void
    {
        $url = $file->kartra_download_url;
        if (!$url) throw new \RuntimeException('No download URL');

        $file->update(['status' => 'downloading']);

        $headers = ['User-Agent' => 'Mozilla/5.0 (compatible; LionImporter/1.0)'];
        if ($this->sessionCookie) $headers['Cookie'] = $this->sessionCookie;

        $directory    = 'kartra-files';
        $safeTitle    = Str::slug($file->display_name ?: "file-{$file->id}");
        $placeholderName = $safeTitle . '-' . $file->id . '.tmp';
        $localPath    = $directory . '/' . $placeholderName;

        Storage::disk('local')->makeDirectory($directory);
        $absolutePath = Storage::disk('local')->path($localPath);

        // Stream the file — Kartra redirects to a signed CDN URL
        $response = Http::timeout(120)
            ->withHeaders($headers)
            ->withOptions(['allow_redirects' => true])
            ->sink($absolutePath)
            ->get($url);

        if ($response->failed()) {
            throw new \RuntimeException("HTTP {$response->status()} downloading file");
        }

        // Try to get actual filename from Content-Disposition header
        $disposition = $response->header('Content-Disposition') ?: '';
        $originalName = null;
        if (preg_match('/filename[^;=\n]*=["\']*([^";\n]+)/i', $disposition, $m)) {
            $originalName = trim($m[1], '"\'');
        }

        // Determine extension from MIME or original name
        $contentType = $response->header('Content-Type') ?: 'application/octet-stream';
        $ext  = $originalName ? pathinfo($originalName, PATHINFO_EXTENSION) : $this->mimeToExt($contentType);
        $finalName = $safeTitle . '-' . $file->id . ($ext ? '.' . $ext : '');
        $finalPath = $directory . '/' . $finalName;

        // Rename from placeholder
        Storage::disk('local')->move($localPath, $finalPath);

        $file->update([
            'status'            => 'downloaded',
            'local_path'        => $finalPath,
            'local_filename'    => $finalName,
            'original_filename' => $originalName,
            'file_size'         => Storage::disk('local')->size($finalPath),
            'mime_type'         => $contentType,
        ]);
    }

    private function mimeToExt(string $mime): string
    {
        $map = [
            'application/pdf'                                                   => 'pdf',
            'application/msword'                                                => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-powerpoint'                                     => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/vnd.ms-excel'                                          => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/zip'                                                   => 'zip',
            'audio/mpeg'                                                        => 'mp3',
            'audio/wav'                                                         => 'wav',
            'image/jpeg'                                                        => 'jpg',
            'image/png'                                                         => 'png',
        ];
        $base = strtolower(explode(';', $mime)[0]);
        return $map[$base] ?? '';
    }
}
