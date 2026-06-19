<?php

namespace App\Services;

use App\Models\VideoAsset;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Uploads local video files to Vimeo using the Vimeo API v3.
 *
 * Required env vars:
 *   VIMEO_ACCESS_TOKEN  — personal access token with upload scope
 *   VIMEO_PRIVACY       — default privacy ('disable' = embed-only, 'anybody', 'password', etc.)
 *
 * Vimeo API docs: https://developer.vimeo.com/api/upload/videos
 */
class VimeoUploadService
{
    private string $accessToken;
    private string $defaultPrivacy;
    private string $apiBase = 'https://api.vimeo.com';

    public function __construct()
    {
        $this->accessToken    = config('services.vimeo.access_token', '');
        $this->defaultPrivacy = config('services.vimeo.privacy', 'disable');
    }

    /**
     * Upload a VideoAsset to Vimeo. Updates the model with Vimeo IDs.
     * Returns true on success, false on failure.
     */
    public function upload(VideoAsset $asset): bool
    {
        if (!$this->accessToken) {
            Log::error('[Vimeo] No access token configured (VIMEO_ACCESS_TOKEN).');
            $asset->update(['vimeo_status' => 'failed', 'vimeo_upload_error' => 'No Vimeo access token configured.']);
            return false;
        }

        if (!$asset->isDownloaded()) {
            $asset->update(['vimeo_status' => 'failed', 'vimeo_upload_error' => 'Local file not found on server.']);
            return false;
        }

        $filePath = $asset->localAbsolutePath();
        $fileSize = filesize($filePath);

        $asset->update(['vimeo_status' => 'uploading']);

        try {
            // Step 1: Create a new video on Vimeo (tus resumable upload approach)
            $uploadUri = $this->createUploadTicket($asset, $fileSize);

            // Step 2: Upload the file using tus protocol
            $this->tusUpload($uploadUri, $filePath, $fileSize);

            // Step 3: Retrieve the Vimeo video URI from the upload
            $videoUri = $this->getVideoUri($uploadUri);

            // Step 4: Set privacy and metadata
            $videoId = ltrim(basename($videoUri), '/');
            $this->setVideoMetadata($videoUri, $asset);

            $asset->update([
                'vimeo_status'      => 'uploaded',
                'vimeo_video_id'    => $videoId,
                'vimeo_uri'         => $videoUri,
                'vimeo_url'         => 'https://vimeo.com/' . $videoId,
                'vimeo_embed_url'   => 'https://player.vimeo.com/video/' . $videoId,
                'vimeo_upload_error' => null,
                'vimeo_uploaded_at' => now(),
            ]);

            Log::info('[Vimeo] Upload successful', ['asset_id' => $asset->id, 'vimeo_id' => $videoId]);
            return true;

        } catch (\Throwable $e) {
            $asset->update([
                'vimeo_status'       => 'failed',
                'vimeo_upload_error' => $e->getMessage(),
            ]);
            Log::error('[Vimeo] Upload failed', ['asset_id' => $asset->id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * After upload, apply the Vimeo embed URL back to the linked training content block.
     */
    public function syncToContentBlock(VideoAsset $asset): void
    {
        if (!$asset->isOnVimeo() || !$asset->content_block_id) return;

        $asset->contentBlock?->update([
            'video_url'      => $asset->vimeo_embed_url,
            'video_provider' => 'vimeo',
        ]);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function createUploadTicket(VideoAsset $asset, int $fileSize): string
    {
        $response = Http::withToken($this->accessToken)
            ->withHeaders(['Accept' => 'application/vnd.vimeo.*+json;version=3.4'])
            ->post("{$this->apiBase}/me/videos", [
                'upload' => [
                    'approach' => 'tus',
                    'size'     => $fileSize,
                ],
                'name'        => $asset->title,
                'description' => $asset->description ?? '',
                'privacy'     => ['view' => $asset->vimeo_privacy ?? $this->defaultPrivacy],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Vimeo create ticket failed: ' . $response->body());
        }

        $uploadLink = $response->json('upload.upload_link');
        if (!$uploadLink) {
            throw new \RuntimeException('Vimeo did not return an upload_link: ' . $response->body());
        }

        return $uploadLink;
    }

    private function tusUpload(string $uploadUri, string $filePath, int $fileSize): void
    {
        $chunkSize = 128 * 1024 * 1024; // 128 MB chunks
        $offset    = 0;
        $handle    = fopen($filePath, 'rb');

        if (!$handle) {
            throw new \RuntimeException("Cannot open file for reading: $filePath");
        }

        try {
            while ($offset < $fileSize) {
                $chunk = fread($handle, $chunkSize);
                if ($chunk === false) break;

                $chunkLen = strlen($chunk);
                $response = Http::withToken($this->accessToken)
                    ->withHeaders([
                        'Tus-Resumable'  => '1.0.0',
                        'Upload-Offset'  => $offset,
                        'Content-Type'   => 'application/offset+octet-stream',
                        'Content-Length' => $chunkLen,
                    ])
                    ->withBody($chunk, 'application/offset+octet-stream')
                    ->patch($uploadUri);

                if ($response->status() !== 204) {
                    throw new \RuntimeException("TUS upload chunk failed at offset $offset: " . $response->body());
                }

                $offset = (int) $response->header('Upload-Offset');
            }
        } finally {
            fclose($handle);
        }
    }

    private function getVideoUri(string $uploadUri): string
    {
        // The video URI is embedded in the upload URL path for tus uploads
        // e.g. https://files.tus.vimeo.com/files/{upload_id}
        // We query the Vimeo API to get the completed video
        $response = Http::withToken($this->accessToken)
            ->withHeaders([
                'Tus-Resumable' => '1.0.0',
                'Accept'        => 'application/vnd.vimeo.*+json;version=3.4',
            ])
            ->head($uploadUri);

        // Extract from Upload-Metadata header or fallback to /me/videos search
        $metadata = $response->header('Upload-Metadata') ?? '';
        if (preg_match('/video_id ([^,]+)/', $metadata, $m)) {
            return '/videos/' . base64_decode($m[1]);
        }

        // Fallback: list recent videos and find the one just uploaded
        $listResponse = Http::withToken($this->accessToken)
            ->withHeaders(['Accept' => 'application/vnd.vimeo.*+json;version=3.4'])
            ->get("{$this->apiBase}/me/videos", ['sort' => 'date', 'direction' => 'desc', 'per_page' => 1]);

        $uri = $listResponse->json('data.0.uri');
        if (!$uri) {
            throw new \RuntimeException('Could not determine Vimeo video URI after upload.');
        }

        return $uri;
    }

    private function setVideoMetadata(string $videoUri, VideoAsset $asset): void
    {
        Http::withToken($this->accessToken)
            ->withHeaders(['Accept' => 'application/vnd.vimeo.*+json;version=3.4'])
            ->patch("{$this->apiBase}{$videoUri}", [
                'name'        => $asset->title,
                'description' => $asset->description ?? '',
                'privacy'     => ['view' => $asset->vimeo_privacy ?? $this->defaultPrivacy],
            ]);
    }
}
