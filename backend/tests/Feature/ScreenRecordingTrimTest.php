<?php

namespace Tests\Feature;

use App\Jobs\TrimScreenRecordingJob;
use App\Models\Role;
use App\Models\ScreenRecording;
use App\Models\User;
use App\Services\ScreenRecording\VideoTrimmer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Trimming a stored recording.
 *
 * The risk that matters here is losing a capture that cannot be made again, so
 * the tests lean on the non-destructive guarantees: the original object must
 * survive every trim, a second trim must re-cut the original rather than the
 * previous result, and revert must put things back exactly.
 */
class ScreenRecordingTrimTest extends TestCase
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
            'name'     => 'Trim Admin',
            'email'    => 'trim-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $user->forceFill([
            'role_id'   => Role::findByName(Role::SUPER_ADMIN)->id,
            'is_active' => true,
        ])->save();

        return $user->refresh();
    }

    private function recording(array $attributes = []): ScreenRecording
    {
        $recording = ScreenRecording::create(array_merge([
            'user_id'          => $this->admin()->id,
            'title'            => 'Walkthrough',
            'disk'             => 'recordings-test',
            'path'             => 'training-recordings/2026/08/original.webm',
            'mime'             => 'video/webm',
            'size_bytes'       => 4096,
            'duration_seconds' => 30,
            'status'           => ScreenRecording::STATUS_READY,
        ], $attributes));

        Storage::disk('recordings-test')->put($recording->path, 'not-really-a-video');

        return $recording->refresh();
    }

    private function ffmpegAvailable(): bool
    {
        return is_executable((string) config('screen-recordings.trim.ffmpeg'));
    }

    /** A real 5-second clip, so the encode path is exercised for real. */
    private function makeRealVideo(string $path, int $seconds = 5): void
    {
        $process = new Process([
            config('screen-recordings.trim.ffmpeg'), '-y', '-nostdin',
            '-f', 'lavfi', '-i', "testsrc=size=320x240:rate=15:duration={$seconds}",
            '-f', 'lavfi', '-i', "sine=frequency=440:duration={$seconds}",
            '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-shortest', $path,
        ], timeout: 120);

        $process->mustRun();
    }

    // ── Queueing ──────────────────────────────────────────────────────────────

    public function test_an_admin_can_queue_a_trim(): void
    {
        Queue::fake();
        $recording = $this->recording();

        $this->actingAs($this->admin())
            ->post("/admin/screen-recordings/{$recording->uuid}/trim", [
                'trim_start' => 2.5,
                'trim_end'   => 20,
            ])
            ->assertRedirect();

        $this->assertSame(ScreenRecording::TRIM_QUEUED, $recording->refresh()->trim_status);

        Queue::assertPushed(TrimScreenRecordingJob::class, function ($job) use ($recording) {
            return $job->recordingId === $recording->id
                && $job->start === 2.5
                && $job->end === 20.0;
        });
    }

    public function test_members_cannot_trim(): void
    {
        Queue::fake();
        $recording = $this->recording();

        $member = User::create([
            'name' => 'M', 'email' => 'm'.uniqid().'@example.com', 'password' => 'password',
        ]);
        $member->forceFill([
            'role_id'   => Role::findByName(Role::PAID_MEMBER)->id,
            'is_active' => true,
        ])->save();

        $this->actingAs($member)
            ->post("/admin/screen-recordings/{$recording->uuid}/trim", ['trim_start' => 0, 'trim_end' => 5])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_an_end_before_the_start_is_rejected(): void
    {
        Queue::fake();
        $recording = $this->recording();

        $this->actingAs($this->admin())
            ->post("/admin/screen-recordings/{$recording->uuid}/trim", [
                'trim_start' => 10,
                'trim_end'   => 4,
            ])
            ->assertSessionHasErrors('trim_end');

        Queue::assertNothingPushed();
        $this->assertNull($recording->refresh()->trim_status);
    }

    public function test_a_sliver_of_a_second_is_rejected(): void
    {
        Queue::fake();
        $recording = $this->recording();

        $this->actingAs($this->admin())
            ->post("/admin/screen-recordings/{$recording->uuid}/trim", [
                'trim_start' => 3,
                'trim_end'   => 3.2,
            ])
            ->assertSessionHasErrors('trim_end');

        Queue::assertNothingPushed();
    }

    public function test_a_second_trim_is_refused_while_one_is_running(): void
    {
        Queue::fake();
        $recording = $this->recording(['trim_status' => ScreenRecording::TRIM_PROCESSING]);

        $this->actingAs($this->admin())
            ->post("/admin/screen-recordings/{$recording->uuid}/trim", ['trim_start' => 0, 'trim_end' => 5])
            ->assertRedirect();

        Queue::assertNothingPushed();
    }

    // ── The job ───────────────────────────────────────────────────────────────

    public function test_a_failing_trim_leaves_the_recording_playable(): void
    {
        $recording = $this->recording();

        // The stored bytes are not a video, so ffmpeg will refuse them.
        try {
            (new TrimScreenRecordingJob($recording->id, 1, 4))->handle(app(VideoTrimmer::class));
        } catch (\Throwable) {
            // The job rethrows so the queue records the failure; that is expected.
        }

        $recording->refresh();

        $this->assertSame(ScreenRecording::TRIM_FAILED, $recording->trim_status);
        $this->assertNotNull($recording->trim_error);

        // The whole point: the file it was already serving is untouched.
        $this->assertSame('training-recordings/2026/08/original.webm', $recording->path);
        $this->assertTrue($recording->isReady());
        Storage::disk('recordings-test')->assertExists($recording->path);
    }

    // ── The real thing ────────────────────────────────────────────────────────

    public function test_a_real_trim_shortens_the_video_and_keeps_the_original(): void
    {
        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg is not installed on this machine.');
        }

        $source = sys_get_temp_dir().'/trim-source-'.uniqid().'.mp4';
        $this->makeRealVideo($source, 5);

        $recording = $this->recording([
            'path'             => 'training-recordings/2026/08/real.mp4',
            'mime'             => 'video/mp4',
            'duration_seconds' => 5,
        ]);

        Storage::disk('recordings-test')->put($recording->path, file_get_contents($source));
        @unlink($source);

        $originalKey = $recording->path;

        (new TrimScreenRecordingJob($recording->id, 1.0, 4.0))->handle(app(VideoTrimmer::class));

        $recording->refresh();

        $this->assertNull($recording->trim_status, $recording->trim_error ?? '');
        $this->assertNotSame($originalKey, $recording->path, 'The trim must land on a new key.');
        $this->assertSame($originalKey, $recording->original_path);
        $this->assertSame(5, $recording->original_duration_seconds);
        $this->assertSame(3, $recording->duration_seconds);
        $this->assertSame('video/mp4', $recording->mime);

        // Both files exist: the trim is non-destructive.
        Storage::disk('recordings-test')->assertExists($originalKey);
        Storage::disk('recordings-test')->assertExists($recording->path);

        // And a poster was regenerated, because the old first frame is gone.
        $this->assertNotNull($recording->thumbnail_path);
        Storage::disk('recordings-test')->assertExists($recording->thumbnail_path);
    }

    public function test_a_second_trim_recuts_the_original_rather_than_the_trim(): void
    {
        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg is not installed on this machine.');
        }

        $source = sys_get_temp_dir().'/trim-source-'.uniqid().'.mp4';
        $this->makeRealVideo($source, 6);

        $recording = $this->recording([
            'path'             => 'training-recordings/2026/08/real.mp4',
            'mime'             => 'video/mp4',
            'duration_seconds' => 6,
        ]);
        Storage::disk('recordings-test')->put($recording->path, file_get_contents($source));
        @unlink($source);

        $originalKey = $recording->path;

        // Narrow to 2s...
        (new TrimScreenRecordingJob($recording->id, 2.0, 4.0))->handle(app(VideoTrimmer::class));
        $firstTrimKey = $recording->refresh()->path;
        $this->assertSame(2, $recording->duration_seconds);

        // ...then widen back out to 5s, which is only possible if the source
        // was the 6-second original and not the 2-second result.
        (new TrimScreenRecordingJob($recording->id, 0.5, 5.5))->handle(app(VideoTrimmer::class));
        $recording->refresh();

        $this->assertSame(5, $recording->duration_seconds);
        $this->assertSame($originalKey, $recording->original_path);
        Storage::disk('recordings-test')->assertExists($originalKey);
        // The superseded intermediate is cleaned up; only original + current remain.
        Storage::disk('recordings-test')->assertMissing($firstTrimKey);
    }

    public function test_revert_restores_the_original_file_and_length(): void
    {
        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg is not installed on this machine.');
        }

        $source = sys_get_temp_dir().'/trim-source-'.uniqid().'.mp4';
        $this->makeRealVideo($source, 5);

        $recording = $this->recording([
            'path'             => 'training-recordings/2026/08/real.mp4',
            'mime'             => 'video/mp4',
            'duration_seconds' => 5,
        ]);
        Storage::disk('recordings-test')->put($recording->path, file_get_contents($source));
        @unlink($source);

        $originalKey = $recording->path;

        (new TrimScreenRecordingJob($recording->id, 1.0, 3.0))->handle(app(VideoTrimmer::class));
        $trimmedKey = $recording->refresh()->path;

        $this->actingAs($this->admin())
            ->post("/admin/screen-recordings/{$recording->uuid}/trim/revert")
            ->assertRedirect();

        $recording->refresh();

        $this->assertSame($originalKey, $recording->path);
        $this->assertNull($recording->original_path);
        $this->assertNull($recording->trim_start);
        $this->assertSame(5, $recording->duration_seconds);
        Storage::disk('recordings-test')->assertExists($originalKey);
        Storage::disk('recordings-test')->assertMissing($trimmedKey);
    }

    public function test_revert_is_refused_when_there_is_nothing_to_revert_to(): void
    {
        $recording = $this->recording();

        $this->actingAs($this->admin())
            ->post("/admin/screen-recordings/{$recording->uuid}/trim/revert")
            ->assertRedirect()
            ->assertSessionHas('error');
    }
}
