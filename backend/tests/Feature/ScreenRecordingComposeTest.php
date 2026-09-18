<?php

namespace Tests\Feature;

use App\Jobs\ComposeScreenRecordingJob;
use App\Models\Role;
use App\Models\ScreenRecording;
use App\Models\User;
use App\Services\ScreenRecording\VideoComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Combining several recordings into one file.
 *
 * The interesting case is the one that happens in practice: clips that do not
 * match. A 1080p screen capture with sound followed by a 720p silent webcam
 * clip cannot simply be concatenated, and getting that wrong produces either an
 * ffmpeg error or a video with no audio after the first clip.
 */
class ScreenRecordingComposeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleSeeder::class);
        Storage::fake('recordings-test');
        config()->set('screen-recordings.disk', 'recordings-test');
    }

    private function admin(): User
    {
        $user = User::create([
            'name'     => 'Compose Admin',
            'email'    => 'compose-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $user->forceFill([
            'role_id'   => Role::findByName(Role::SUPER_ADMIN)->id,
            'is_active' => true,
        ])->save();

        return $user->refresh();
    }

    private function source(string $title, string $body = 'bytes'): ScreenRecording
    {
        $recording = ScreenRecording::create([
            'user_id'          => $this->admin()->id,
            'title'            => $title,
            'disk'             => 'recordings-test',
            'path'             => 'training-recordings/'.uniqid().'.mp4',
            'mime'             => 'video/mp4',
            'size_bytes'       => 1024,
            'duration_seconds' => 4,
            'status'           => ScreenRecording::STATUS_READY,
        ]);

        Storage::disk('recordings-test')->put($recording->path, $body);

        return $recording->refresh();
    }

    private function ffmpegAvailable(): bool
    {
        return is_executable((string) config('screen-recordings.trim.ffmpeg'))
            && is_executable((string) config('screen-recordings.trim.ffprobe'));
    }

    /**
     * @param bool $silent produce a clip with no audio track at all
     */
    private function makeRealVideo(string $path, int $seconds, int $width, int $height, bool $silent = false): void
    {
        $command = [
            config('screen-recordings.trim.ffmpeg'), '-y', '-nostdin',
            '-f', 'lavfi', '-i', "testsrc=size={$width}x{$height}:rate=25:duration={$seconds}",
        ];

        if (! $silent) {
            $command = [...$command, '-f', 'lavfi', '-i', "sine=frequency=440:duration={$seconds}"];
        }

        $command = [
            ...$command,
            '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p',
        ];

        if (! $silent) {
            $command = [...$command, '-c:a', 'aac', '-shortest'];
        }

        (new Process([...$command, $path], timeout: 120))->mustRun();
    }

    private function storeReal(string $title, int $seconds, int $w, int $h, bool $silent = false): ScreenRecording
    {
        $tmp = sys_get_temp_dir().'/compose-'.uniqid().'.mp4';
        $this->makeRealVideo($tmp, $seconds, $w, $h, $silent);

        $recording = $this->source($title, file_get_contents($tmp));
        @unlink($tmp);

        return $recording;
    }

    // ── The picker and queueing ───────────────────────────────────────────────

    public function test_an_admin_can_open_the_combine_page(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/screen-recordings/combine')
            ->assertOk()
            ->assertSee('Pick your videos');
    }

    public function test_members_cannot_combine(): void
    {
        $member = User::create([
            'name' => 'M', 'email' => 'm'.uniqid().'@example.com', 'password' => 'password',
        ]);
        $member->forceFill([
            'role_id'   => Role::findByName(Role::PAID_MEMBER)->id,
            'is_active' => true,
        ])->save();

        $this->actingAs($member)->get('/admin/screen-recordings/combine')->assertForbidden();
        $this->actingAs($member)->post('/admin/screen-recordings/combine', [
            'title' => 'x', 'clips' => [1, 2],
        ])->assertForbidden();
    }

    public function test_combining_creates_a_composition_in_the_submitted_order(): void
    {
        Queue::fake();

        $first  = $this->source('Intro');
        $second = $this->source('Main');
        $third  = $this->source('Outro');

        $this->actingAs($this->admin())
            ->post('/admin/screen-recordings/combine', [
                'title' => 'September update',
                // Deliberately not id order — the running order is what was picked.
                'clips' => [$third->id, $first->id, $second->id],
            ])
            ->assertRedirect();

        $composition = ScreenRecording::where('title', 'September update')->firstOrFail();

        $this->assertTrue($composition->isComposition());
        $this->assertSame(ScreenRecording::STATUS_RENDERING, $composition->status);
        $this->assertFalse($composition->isReady(), 'A composition has no file until it is built.');

        $this->assertSame(
            ['Outro', 'Intro', 'Main'],
            $composition->clips->map->label()->all(),
        );

        Queue::assertPushed(ComposeScreenRecordingJob::class,
            fn ($job) => $job->compositionId === $composition->id);
    }

    public function test_one_video_is_not_enough(): void
    {
        Queue::fake();
        $only = $this->source('Only one');

        $this->actingAs($this->admin())
            ->post('/admin/screen-recordings/combine', ['title' => 'Nope', 'clips' => [$only->id]])
            ->assertSessionHasErrors('clips');

        Queue::assertNothingPushed();
    }

    public function test_the_same_recording_can_appear_twice(): void
    {
        Queue::fake();
        $bumper = $this->source('Bumper');
        $body   = $this->source('Body');

        $this->actingAs($this->admin())
            ->post('/admin/screen-recordings/combine', [
                'title' => 'With bookends',
                'clips' => [$bumper->id, $body->id, $bumper->id],
            ])
            ->assertRedirect();

        $composition = ScreenRecording::where('title', 'With bookends')->firstOrFail();

        $this->assertSame(['Bumper', 'Body', 'Bumper'], $composition->clips->map->label()->all());
    }

    // ── Building ──────────────────────────────────────────────────────────────

    public function test_a_failed_build_records_why_and_leaves_no_broken_file(): void
    {
        $composition = ScreenRecording::create([
            'user_id' => $this->admin()->id,
            'title'   => 'Doomed',
            'disk'    => 'recordings-test',
            'source'  => ScreenRecording::SOURCE_COMPOSITION,
            'status'  => ScreenRecording::STATUS_RENDERING,
        ]);

        // Both sources hold text, not video, so ffmpeg will refuse them.
        foreach ([$this->source('A'), $this->source('B')] as $i => $src) {
            $composition->clips()->create([
                'source_recording_id' => $src->id,
                'source_title'        => $src->title,
                'sort_order'          => ($i + 1) * 10,
            ]);
        }

        try {
            (new ComposeScreenRecordingJob($composition->id))->handle(app(VideoComposer::class));
        } catch (\Throwable) {
            // Rethrown so the queue records it; expected.
        }

        $composition->refresh();

        $this->assertSame(ScreenRecording::STATUS_FAILED, $composition->status);
        $this->assertNotNull($composition->upload_error);
        $this->assertNull($composition->path);
    }

    public function test_a_deleted_source_stops_a_rebuild_rather_than_producing_a_short_video(): void
    {
        $composition = ScreenRecording::create([
            'user_id' => $this->admin()->id,
            'title'   => 'Missing a piece',
            'disk'    => 'recordings-test',
            'source'  => ScreenRecording::SOURCE_COMPOSITION,
            'status'  => ScreenRecording::STATUS_RENDERING,
        ]);

        $kept = $this->source('Kept');
        $gone = $this->source('Gone');

        foreach ([$kept, $gone] as $i => $src) {
            $composition->clips()->create([
                'source_recording_id' => $src->id,
                'source_title'        => $src->title,
                'sort_order'          => ($i + 1) * 10,
            ]);
        }

        $gone->forceDelete();

        try {
            (new ComposeScreenRecordingJob($composition->id))->handle(app(VideoComposer::class));
        } catch (\Throwable) {
        }

        $composition->refresh();

        $this->assertSame(ScreenRecording::STATUS_FAILED, $composition->status);
        $this->assertStringContainsString('Gone', $composition->upload_error);

        // The clip row survives so the sequence still reads correctly.
        $this->assertSame(['Kept', 'Gone'], $composition->clips->map->label()->all());
    }

    // ── The real thing ────────────────────────────────────────────────────────

    public function test_two_mismatched_clips_are_joined_into_one_playable_video(): void
    {
        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg is not installed on this machine.');
        }

        // The realistic pairing: a wide clip with sound, then a smaller silent
        // one. Different resolution, different aspect, different stream layout.
        $screen = $this->storeReal('Screen walkthrough', 3, 640, 360);
        $webcam = $this->storeReal('Webcam announcement', 2, 480, 480, silent: true);

        $composition = ScreenRecording::create([
            'user_id' => $this->admin()->id,
            'title'   => 'Combined',
            'disk'    => 'recordings-test',
            'source'  => ScreenRecording::SOURCE_COMPOSITION,
            'status'  => ScreenRecording::STATUS_RENDERING,
        ]);

        foreach ([$screen, $webcam] as $i => $src) {
            $composition->clips()->create([
                'source_recording_id' => $src->id,
                'source_title'        => $src->title,
                'sort_order'          => ($i + 1) * 10,
            ]);
        }

        (new ComposeScreenRecordingJob($composition->id))->handle(app(VideoComposer::class));

        $composition->refresh();

        $this->assertSame(ScreenRecording::STATUS_READY, $composition->status, $composition->upload_error ?? '');
        $this->assertTrue($composition->isReady());
        $this->assertSame('video/mp4', $composition->mime);
        Storage::disk('recordings-test')->assertExists($composition->path);

        // Roughly the two clips end to end (3s + 2s), allowing a frame either way.
        $this->assertEqualsWithDelta(5, $composition->duration_seconds, 1);

        // The shared canvas takes the widest and the tallest, so nothing is cropped.
        $this->assertSame(640, $composition->width);
        $this->assertSame(480, $composition->height);

        // Sources are untouched: combining copies, it does not consume.
        Storage::disk('recordings-test')->assertExists($screen->refresh()->path);
        Storage::disk('recordings-test')->assertExists($webcam->refresh()->path);

        $this->assertNotNull($composition->thumbnail_path);
    }

    public function test_rebuilding_replaces_the_file_and_keeps_the_recording_playable(): void
    {
        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg is not installed on this machine.');
        }

        $a = $this->storeReal('A', 2, 320, 240);
        $b = $this->storeReal('B', 2, 320, 240);

        $composition = ScreenRecording::create([
            'user_id' => $this->admin()->id,
            'title'   => 'Rebuildable',
            'disk'    => 'recordings-test',
            'source'  => ScreenRecording::SOURCE_COMPOSITION,
            'status'  => ScreenRecording::STATUS_RENDERING,
        ]);

        foreach ([$a, $b] as $i => $src) {
            $composition->clips()->create([
                'source_recording_id' => $src->id,
                'source_title'        => $src->title,
                'sort_order'          => ($i + 1) * 10,
            ]);
        }

        (new ComposeScreenRecordingJob($composition->id))->handle(app(VideoComposer::class));
        $firstBuild = $composition->refresh()->path;

        $this->actingAs($this->admin())
            ->post("/admin/screen-recordings/{$composition->uuid}/rebuild")
            ->assertRedirect();

        $composition->refresh();

        $this->assertNotSame($firstBuild, $composition->path, 'A rebuild writes a new key.');
        $this->assertTrue($composition->isReady());
        Storage::disk('recordings-test')->assertExists($composition->path);
        Storage::disk('recordings-test')->assertMissing($firstBuild);
    }
}
