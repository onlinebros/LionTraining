<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\ScreenRecording;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The screen-recording studio and the library behind it.
 *
 * Two things carry real risk here and are covered accordingly: the chunked
 * upload must reassemble to exactly the bytes the browser sent — a video is
 * unplayable if it does not — and a recording must never be reachable by
 * someone the admin did not mean to show it to.
 */
class ScreenRecordingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleSeeder::class);
        Storage::fake('recordings-test');
        config()->set('screen-recordings.disk', 'recordings-test');
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name'     => 'Test '.$role,
            'email'    => $role.'-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $user->forceFill([
            'role_id'   => Role::findByName($role)->id,
            'is_active' => true,
        ])->save();

        return $user->refresh();
    }

    private function admin(): User
    {
        return $this->user(Role::SUPER_ADMIN);
    }

    private function member(): User
    {
        return $this->user(Role::PAID_MEMBER);
    }

    /** A stored, playable recording without going through the upload flow. */
    private function storedRecording(array $attributes = []): ScreenRecording
    {
        $recording = ScreenRecording::create(array_merge([
            'user_id'    => $this->admin()->id,
            'title'      => 'How to add a property',
            'disk'       => 'recordings-test',
            'path'       => 'training-recordings/2026/08/test.webm',
            'mime'       => 'video/webm',
            'size_bytes' => 2048,
            'status'     => ScreenRecording::STATUS_READY,
        ], $attributes));

        Storage::disk('recordings-test')->put($recording->path, 'video-bytes');

        return $recording->refresh();
    }

    // ── Access to the studio ──────────────────────────────────────────────────

    public function test_admin_can_open_the_studio_and_library(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/screen-recordings/studio')->assertOk();
        $this->actingAs($admin)->get('/admin/screen-recordings')->assertOk();
    }

    public function test_members_cannot_reach_the_studio(): void
    {
        $this->actingAs($this->member())->get('/admin/screen-recordings/studio')->assertForbidden();
        $this->actingAs($this->member())->get('/admin/screen-recordings')->assertForbidden();
    }

    // ── The chunked upload ────────────────────────────────────────────────────

    public function test_a_chunked_upload_reassembles_to_the_original_bytes(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson('/admin/screen-recordings', [
            'source'          => 'screen+camera',
            'webcam_position' => 'bottom-left',
            'has_mic_audio'   => true,
            'title'           => 'Walkthrough',
        ])->assertCreated();

        $recording = ScreenRecording::where('uuid', $response->json('uuid'))->firstOrFail();
        $this->assertSame('bottom-left', $recording->webcam_position);

        $first  = str_repeat('a', 1500);
        $second = str_repeat('b', 900);

        $this->actingAs($admin)->post(
            "/admin/screen-recordings/{$recording->uuid}/chunk",
            ['offset' => 0, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', $first)]
        )->assertOk()->assertJson(['bytes_received' => 1500]);

        $this->actingAs($admin)->post(
            "/admin/screen-recordings/{$recording->uuid}/chunk",
            ['offset' => 1500, 'chunk' => UploadedFile::fake()->createWithContent('chunk.bin', $second)]
        )->assertOk()->assertJson(['bytes_received' => 2400]);

        $this->actingAs($admin)->post("/admin/screen-recordings/{$recording->uuid}/finalize", [
            'mime'             => 'video/webm',
            'duration_seconds' => 42,
            'width'            => 1920,
            'height'           => 1080,
        ])->assertOk();

        $recording->refresh();

        $this->assertSame(ScreenRecording::STATUS_READY, $recording->status);
        $this->assertSame(2400, $recording->size_bytes);
        $this->assertSame(42, $recording->duration_seconds);
        $this->assertSame($first.$second, Storage::disk('recordings-test')->get($recording->path));

        // The part-file must not survive: the droplet's disk is small.
        $this->assertFileDoesNotExist(
            storage_path('app/'.config('screen-recordings.temp_path').'/'.$recording->uuid.'.part')
        );
    }

    public function test_a_replayed_chunk_does_not_duplicate_bytes(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)
            ->postJson('/admin/screen-recordings', ['source' => 'screen'])
            ->assertCreated();

        $uuid  = $response->json('uuid');
        $bytes = str_repeat('x', 512);

        foreach ([0, 0] as $offset) {
            $this->actingAs($admin)->post("/admin/screen-recordings/{$uuid}/chunk", [
                'offset' => $offset,
                'chunk'  => UploadedFile::fake()->createWithContent('chunk.bin', $bytes),
            ])->assertOk()->assertJson(['bytes_received' => 512]);
        }
    }

    public function test_a_chunk_past_the_end_is_rejected_rather_than_creating_a_hole(): void
    {
        $admin = $this->admin();

        $uuid = $this->actingAs($admin)
            ->postJson('/admin/screen-recordings', ['source' => 'screen'])
            ->json('uuid');

        $this->actingAs($admin)->post("/admin/screen-recordings/{$uuid}/chunk", [
            'offset' => 4096,
            'chunk'  => UploadedFile::fake()->createWithContent('chunk.bin', 'late'),
        ])->assertStatus(422);
    }

    public function test_another_admin_cannot_append_to_someone_elses_upload(): void
    {
        $owner = $this->admin();

        $uuid = $this->actingAs($owner)
            ->postJson('/admin/screen-recordings', ['source' => 'screen'])
            ->json('uuid');

        $this->actingAs($this->admin())->post("/admin/screen-recordings/{$uuid}/chunk", [
            'offset' => 0,
            'chunk'  => UploadedFile::fake()->createWithContent('chunk.bin', 'hello'),
        ])->assertForbidden();
    }

    // ── Who can watch ─────────────────────────────────────────────────────────

    public function test_a_draft_is_invisible_to_members_but_open_to_admins(): void
    {
        $recording = $this->storedRecording(['is_published' => false]);

        $this->actingAs($this->member())->get("/recordings/{$recording->uuid}")->assertForbidden();
        $this->actingAs($this->admin())->get("/recordings/{$recording->uuid}")->assertOk();
    }

    public function test_an_admins_only_recording_stays_closed_to_members_once_published(): void
    {
        $recording = $this->storedRecording([
            'is_published' => true,
            'published_at' => now(),
            'visibility'   => ScreenRecording::VISIBILITY_ADMINS,
        ]);

        $this->actingAs($this->member())->get("/recordings/{$recording->uuid}")->assertForbidden();
    }

    public function test_a_members_recording_opens_for_signed_in_members_only(): void
    {
        $recording = $this->storedRecording([
            'is_published' => true,
            'published_at' => now(),
            'visibility'   => ScreenRecording::VISIBILITY_MEMBERS,
        ]);

        // Signed out first: actingAs persists for the rest of the test.
        $this->get("/recordings/{$recording->uuid}")->assertForbidden();
        $this->actingAs($this->member())->get("/recordings/{$recording->uuid}")->assertOk();
    }

    public function test_a_link_recording_opens_for_a_signed_out_visitor(): void
    {
        $recording = $this->storedRecording([
            'is_published' => true,
            'published_at' => now(),
            'visibility'   => ScreenRecording::VISIBILITY_LINK,
        ]);

        $this->get("/recordings/{$recording->uuid}")->assertOk();
        $this->get("/recordings/{$recording->uuid}/stream")->assertOk();
    }

    public function test_a_role_gated_recording_needs_the_role_level(): void
    {
        $recording = $this->storedRecording([
            'is_published'     => true,
            'published_at'     => now(),
            'visibility'       => ScreenRecording::VISIBILITY_ROLE,
            'required_role_id' => Role::findByName(Role::PAID_MEMBER)->id,
        ]);

        $this->actingAs($this->member())->get("/recordings/{$recording->uuid}")->assertOk();
        $this->actingAs($this->user(Role::FREE_MEMBER))->get("/recordings/{$recording->uuid}")->assertForbidden();
    }

    // ── The pages that show all this ──────────────────────────────────────────

    public function test_the_manage_page_lists_the_presentations_that_use_it(): void
    {
        $admin     = $this->admin();
        $recording = $this->storedRecording(['thumbnail_path' => 'thumbs/test.jpg']);
        $showing   = $this->showingOf($recording, $admin);

        $this->actingAs($admin)
            ->get("/admin/screen-recordings/{$recording->uuid}")
            ->assertOk()
            ->assertSee($recording->title)
            ->assertSee($showing->title);
    }

    public function test_a_recording_used_by_a_presentation_is_not_deleted(): void
    {
        $admin     = $this->admin();
        $recording = $this->storedRecording();
        $this->showingOf($recording, $admin)->delete(); // soft-deleted still holds the FK

        $this->actingAs($admin)
            ->delete("/admin/screen-recordings/{$recording->uuid}")
            ->assertRedirect()
            ->assertSessionHas('error');

        // Refused before storage was touched: the row and its file both remain.
        $this->assertDatabaseHas('screen_recordings', ['id' => $recording->id]);
        Storage::disk('recordings-test')->assertExists($recording->path);
    }

    // ── Library management ────────────────────────────────────────────────────

    public function test_publishing_requires_a_stored_file(): void
    {
        $recording = ScreenRecording::create([
            'user_id' => $this->admin()->id,
            'title'   => 'Half an upload',
            'disk'    => 'recordings-test',
            'status'  => ScreenRecording::STATUS_UPLOADING,
        ]);

        $this->actingAs($this->admin())
            ->post("/admin/screen-recordings/{$recording->uuid}/publish", ['publish' => 1])
            ->assertRedirect();

        $this->assertFalse($recording->refresh()->is_published);
    }

    public function test_a_role_gate_without_a_role_is_refused(): void
    {
        $recording = $this->storedRecording();

        $this->actingAs($this->admin())
            ->put("/admin/screen-recordings/{$recording->uuid}", [
                'title'      => 'Still called something',
                'visibility' => ScreenRecording::VISIBILITY_ROLE,
            ])
            ->assertSessionHasErrors('required_role_id');
    }

    public function test_deleting_removes_the_object_from_storage(): void
    {
        $recording = $this->storedRecording();
        $path      = $recording->path;

        $this->actingAs($this->admin())
            ->delete("/admin/screen-recordings/{$recording->uuid}")
            ->assertRedirect(route('admin.screen-recordings.index'));

        Storage::disk('recordings-test')->assertMissing($path);
        $this->assertDatabaseMissing('screen_recordings', ['id' => $recording->id]);
    }

    public function test_a_failed_upload_can_be_stored_from_the_buffered_bytes(): void
    {
        $admin = $this->admin();

        $uuid = $this->actingAs($admin)
            ->postJson('/admin/screen-recordings', ['source' => 'screen'])
            ->json('uuid');

        $this->actingAs($admin)->post("/admin/screen-recordings/{$uuid}/chunk", [
            'offset' => 0,
            'chunk'  => UploadedFile::fake()->createWithContent('chunk.bin', 'captured'),
        ])->assertOk();

        // Finalize ran and the Space rejected it, so the part-file was kept.
        ScreenRecording::where('uuid', $uuid)->update([
            'status'       => ScreenRecording::STATUS_FAILED,
            'upload_error' => 'Connection to nyc3 timed out',
        ]);

        $this->actingAs($admin)
            ->post("/admin/screen-recordings/{$uuid}/retry-store")
            ->assertRedirect();

        $recording = ScreenRecording::where('uuid', $uuid)->firstOrFail();

        $this->assertSame(ScreenRecording::STATUS_READY, $recording->status);
        $this->assertNull($recording->upload_error, 'A successful retry must clear the old error.');
        $this->assertSame('captured', Storage::disk('recordings-test')->get($recording->path));
    }

    public function test_prune_sweeps_abandoned_uploads(): void
    {
        $recording = ScreenRecording::create([
            'user_id' => $this->admin()->id,
            'title'   => 'Closed the tab',
            'disk'    => 'recordings-test',
            'status'  => ScreenRecording::STATUS_UPLOADING,
        ]);

        $recording->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();

        $this->artisan('recordings:prune')->assertExitCode(0);

        $this->assertDatabaseMissing('screen_recordings', ['id' => $recording->id]);
    }

    private function showingOf(ScreenRecording $recording, User $by): \App\Models\Presentation
    {
        return \App\Models\Presentation::create([
            'title'            => 'Tuesday overview',
            'recording_id'     => $recording->id,
            'scheduled_at'     => now()->addDay(),
            'duration_seconds' => 60,
            'created_by'       => $by->id,
        ]);
    }
}
