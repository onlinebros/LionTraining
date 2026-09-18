<?php

namespace Tests\Feature;

use App\Models\Presentation;
use App\Models\Role;
use App\Models\ScreenRecording;
use App\Models\User;
use App\Services\ScreenRecording\MediaInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Bringing in a video that was recorded somewhere else.
 *
 * It goes through the same chunked pipeline as a studio capture, so the risk
 * here is not the transport — it is that an upload is whatever bytes a browser
 * sent. The length, the dimensions and "is this even a video" are therefore
 * decided by ffprobe on the server, and these tests exist mostly to pin that.
 */
class RecordingUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleSeeder::class);
        Storage::fake('uploads-test');
        config(['screen-recordings.disk' => 'uploads-test']);
    }

    private function admin(): User
    {
        $user = User::create([
            'name'     => 'Uploader',
            'email'    => 'up-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $user->forceFill([
            'role_id'   => Role::findByName(Role::SUPER_ADMIN)->id,
            'is_active' => true,
        ])->save();

        return $user->refresh();
    }

    private function member(): User
    {
        $user = User::create([
            'name'     => 'Member',
            'email'    => 'mem-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $user->forceFill([
            'role_id'   => Role::findByName(Role::PAID_MEMBER)->id,
            'is_active' => true,
        ])->save();

        return $user->refresh();
    }

    private function ffmpegAvailable(): bool
    {
        return is_executable((string) config('screen-recordings.trim.ffmpeg'))
            && is_executable((string) config('screen-recordings.trim.ffprobe'));
    }

    /** A real MP4 of a known length, standing in for the file somebody drags in. */
    private function makeMp4(string $path, int $seconds = 4, int $w = 320, int $h = 240): void
    {
        (new Process([
            config('screen-recordings.trim.ffmpeg'), '-y', '-nostdin',
            '-f', 'lavfi', '-i', "testsrc=size={$w}x{$h}:rate=15:duration={$seconds}",
            '-f', 'lavfi', '-i', "sine=frequency=440:duration={$seconds}",
            '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-shortest', $path,
        ], timeout: 120))->mustRun();
    }

    /** Push a whole file through the chunked endpoints, as the browser does. */
    private function upload(User $admin, string $localFile, array $finalize = []): ScreenRecording
    {
        $uuid = $this->actingAs($admin)->postJson('/admin/screen-recordings', [
            'source' => ScreenRecording::SOURCE_UPLOAD,
            'title'  => $finalize['title'] ?? 'Uploaded video',
        ])->assertCreated()->json('uuid');

        $bytes  = file_get_contents($localFile);
        $size   = strlen($bytes);
        $chunk  = 64 * 1024;
        $offset = 0;

        while ($offset < $size) {
            $slice = substr($bytes, $offset, $chunk);

            $this->actingAs($admin)->post("/admin/screen-recordings/{$uuid}/chunk", [
                'offset' => $offset,
                'chunk'  => UploadedFile::fake()->createWithContent('part.bin', $slice),
            ])->assertOk();

            $offset += strlen($slice);
        }

        $this->actingAs($admin)
            ->post("/admin/screen-recordings/{$uuid}/finalize", $finalize)
            ->assertOk();

        return ScreenRecording::where('uuid', $uuid)->firstOrFail();
    }

    // ── Access ────────────────────────────────────────────────────────────────

    public function test_an_admin_can_open_the_upload_page(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/screen-recordings/upload')
            ->assertOk()
            ->assertSee('Drop a video here');
    }

    public function test_members_cannot_upload(): void
    {
        $this->actingAs($this->member())->get('/admin/screen-recordings/upload')->assertForbidden();

        $this->actingAs($this->member())
            ->postJson('/admin/screen-recordings', ['source' => ScreenRecording::SOURCE_UPLOAD])
            ->assertForbidden();
    }

    // ── The round trip ────────────────────────────────────────────────────────

    public function test_an_uploaded_mp4_lands_in_the_library_measured_from_the_file(): void
    {
        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg is not installed on this machine.');
        }

        $source = sys_get_temp_dir().'/upload-'.uniqid().'.mp4';
        $this->makeMp4($source, 4, 320, 240);

        $recording = $this->upload($this->admin(), $source, [
            'title' => 'September call',
            // Deliberately wrong hints, as a browser that cannot decode the
            // codec would send. The server's own measurement must win.
            'duration_seconds' => 999,
            'width'            => 4,
            'height'           => 4,
            'mime'             => 'application/octet-stream',
        ]);

        @unlink($source);

        $this->assertSame(ScreenRecording::STATUS_READY, $recording->status);
        $this->assertSame(ScreenRecording::SOURCE_UPLOAD, $recording->source);
        $this->assertSame('September call', $recording->title);

        $this->assertEqualsWithDelta(4, $recording->duration_seconds, 1, 'Length comes from ffprobe.');
        $this->assertSame(320, $recording->width);
        $this->assertSame(240, $recording->height);
        $this->assertSame('video/mp4', $recording->mime, 'The container decides the type, not the browser.');

        Storage::disk('uploads-test')->assertExists($recording->path);

        // A poster is pulled out of the file, so an upload looks like anything
        // else in the library rather than a grey box.
        $this->assertNotNull($recording->thumbnail_path);
        Storage::disk('uploads-test')->assertExists($recording->thumbnail_path);
    }

    public function test_a_file_that_is_not_a_video_is_refused_and_leaves_nothing_behind(): void
    {
        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg is not installed on this machine.');
        }

        $admin = $this->admin();

        $uuid = $this->actingAs($admin)->postJson('/admin/screen-recordings', [
            'source' => ScreenRecording::SOURCE_UPLOAD,
        ])->assertCreated()->json('uuid');

        $this->actingAs($admin)->post("/admin/screen-recordings/{$uuid}/chunk", [
            'offset' => 0,
            'chunk'  => UploadedFile::fake()->createWithContent('notes.txt', str_repeat('this is not a video ', 200)),
        ])->assertOk();

        $this->actingAs($admin)
            ->post("/admin/screen-recordings/{$uuid}/finalize", [])
            ->assertStatus(422);

        // No half-made row in the library, nothing written to storage, and no
        // orphaned part-file left on the droplet's disk.
        $this->assertNull(ScreenRecording::where('uuid', $uuid)->first());
        $this->assertEmpty(Storage::disk('uploads-test')->allFiles());
        $this->assertFileDoesNotExist(
            storage_path('app/'.config('screen-recordings.temp_path').'/'.$uuid.'.part')
        );
    }

    // ── It behaves like everything else once it is in ─────────────────────────

    public function test_an_uploaded_video_can_be_scheduled_as_a_presentation(): void
    {
        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg is not installed on this machine.');
        }

        $admin  = $this->admin();
        $source = sys_get_temp_dir().'/upload-'.uniqid().'.mp4';
        $this->makeMp4($source, 3);

        $recording = $this->upload($admin, $source, ['title' => 'Recorded on Zoom']);
        @unlink($source);

        // The whole point of putting uploads in the library: everything already
        // built works on them without knowing where they came from.
        $this->actingAs($admin)->get('/admin/presentations/create')
            ->assertOk()
            ->assertSee('Recorded on Zoom');

        $this->actingAs($admin)->post('/admin/presentations', [
            'title'             => 'Zoom replay',
            'recording_id'      => $recording->id,
            'scheduled_at'      => now()->addDay()->format('Y-m-d\TH:i'),
            'replay_visibility' => Presentation::REPLAY_NONE,
        ])->assertRedirect();

        $presentation = Presentation::where('title', 'Zoom replay')->firstOrFail();

        $this->assertSame($recording->id, $presentation->recording_id);
        $this->assertSame($recording->duration_seconds, $presentation->duration_seconds);
    }

    public function test_an_uploaded_video_is_labelled_as_such_in_the_library(): void
    {
        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg is not installed on this machine.');
        }

        $admin  = $this->admin();
        $source = sys_get_temp_dir().'/upload-'.uniqid().'.mp4';
        $this->makeMp4($source, 2);

        $recording = $this->upload($admin, $source);
        @unlink($source);

        $this->assertSame('Uploaded video', $recording->sourceLabel());

        $this->actingAs($admin)->get('/admin/screen-recordings')
            ->assertOk()
            ->assertSee('Uploaded video');
    }

    // ── The inspector on its own ──────────────────────────────────────────────

    public function test_the_inspector_reports_what_a_file_actually_contains(): void
    {
        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg is not installed on this machine.');
        }

        $source = sys_get_temp_dir().'/probe-'.uniqid().'.mp4';
        $this->makeMp4($source, 3, 640, 360);

        $probe = app(MediaInspector::class)->inspect($source);
        @unlink($source);

        $this->assertTrue($probe['ok']);
        $this->assertTrue($probe['has_video']);
        $this->assertTrue($probe['has_audio']);
        $this->assertSame(640, $probe['width']);
        $this->assertSame(360, $probe['height']);
        $this->assertEqualsWithDelta(3, $probe['duration'], 1);
    }

    public function test_the_inspector_rejects_a_text_file(): void
    {
        $path = sys_get_temp_dir().'/not-video-'.uniqid().'.txt';
        file_put_contents($path, 'plain text');

        $probe = app(MediaInspector::class)->inspect($path);
        @unlink($path);

        $this->assertFalse($probe['ok']);
        $this->assertFalse($probe['has_video']);
    }
}
