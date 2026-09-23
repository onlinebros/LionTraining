<?php

namespace Tests\Feature\Training;

use App\Models\KartraFile;
use App\Models\KartraImport;
use App\Models\TrainingCategory;
use App\Models\TrainingContentBlock;
use App\Models\TrainingLesson;
use App\Models\VideoAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Moving the library onto its serving storage.
 *
 * The transfer is 24 GB over a link that will drop at least once, so the
 * behaviour that matters is not the happy path — it is what a second run does
 * after the first one stopped halfway.
 */
class PublishTrainingMediaTest extends TestCase
{
    use RefreshDatabase;

    private TrainingLesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('spaces');

        config([
            'training.disk'          => 'spaces',
            'training.paths.videos'  => 'training/videos',
            'training.paths.files'   => 'training/files',
        ]);

        $category = TrainingCategory::create([
            'name' => 'Module', 'slug' => 'module', 'sort_order' => 0, 'is_active' => true,
        ]);

        $this->lesson = TrainingLesson::create([
            'category_id' => $category->id, 'title' => 'Lesson', 'slug' => 'lesson',
            'sort_order' => 0, 'is_published' => true,
        ]);
    }

    private function video(string $filename, string $body = 'video-bytes'): VideoAsset
    {
        Storage::disk('local')->put("kartra-videos/{$filename}", $body);

        return VideoAsset::create([
            'title'          => $filename,
            'source'         => 'kartra',
            'local_path'     => "kartra-videos/{$filename}",
            'local_filename' => $filename,
            'file_size'      => strlen($body),
            'mime_type'      => 'video/mp4',
            'vimeo_status'   => 'self_hosted',
        ]);
    }

    private function worksheet(string $filename, string $body = '%PDF-1.4'): KartraFile
    {
        Storage::disk('local')->put("kartra-files/{$filename}", $body);

        $import = KartraImport::create([
            'kartra_type' => 'lesson', 'kartra_title' => 'Lesson', 'status' => 'discovered',
        ]);

        $file = KartraFile::create([
            'kartra_import_id' => $import->id,
            'display_name'     => $filename,
            'local_path'       => "kartra-files/{$filename}",
            'local_filename'   => $filename,
            'file_size'        => strlen($body),
            'mime_type'        => 'application/pdf',
            'status'           => 'downloaded',
        ]);

        TrainingContentBlock::create([
            'lesson_id' => $this->lesson->id,
            'type'      => 'download',
            'title'     => $filename,
            'file_path' => "kartra-files/{$filename}",
            'file_disk' => 'local',
            'file_name' => $filename,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        return $file;
    }

    public function test_it_copies_videos_and_records_where_they_landed(): void
    {
        $asset = $this->video('lesson-1.mp4');

        $this->artisan('training:publish-media', ['--videos' => true])->assertSuccessful();

        Storage::disk('spaces')->assertExists('training/videos/lesson-1.mp4');

        $asset->refresh();
        $this->assertSame('spaces', $asset->disk);
        $this->assertSame('training/videos/lesson-1.mp4', $asset->storage_path);
        $this->assertNotNull($asset->published_at);

        // Copied, not moved: the extract cannot be downloaded again.
        Storage::disk('local')->assertExists('kartra-videos/lesson-1.mp4');
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $asset = $this->video('lesson-1.mp4');

        $this->artisan('training:publish-media', ['--videos' => true, '--dry-run' => true])
            ->assertSuccessful();

        Storage::disk('spaces')->assertMissing('training/videos/lesson-1.mp4');
        $this->assertNull($asset->refresh()->disk);
    }

    public function test_a_second_run_skips_what_is_already_published(): void
    {
        $this->video('lesson-1.mp4');

        $this->artisan('training:publish-media', ['--videos' => true])->assertSuccessful();

        // Change the object underneath so a re-upload would be detectable.
        Storage::disk('spaces')->put('training/videos/lesson-1.mp4', 'STALE');

        $this->artisan('training:publish-media', ['--videos' => true])->assertSuccessful();

        // Untouched: the row already names the disk, so it was skipped.
        $this->assertSame('STALE', Storage::disk('spaces')->get('training/videos/lesson-1.mp4'));
    }

    public function test_verify_repairs_an_object_that_does_not_match(): void
    {
        $this->video('lesson-1.mp4', 'the-real-bytes');

        $this->artisan('training:publish-media', ['--videos' => true])->assertSuccessful();

        // The failure this guards against: an upload cut off partway leaves a
        // short object, and every later run would otherwise skip it forever.
        Storage::disk('spaces')->put('training/videos/lesson-1.mp4', 'trunc');

        $this->artisan('training:publish-media', ['--videos' => true, '--verify' => true])
            ->assertSuccessful();

        $this->assertSame('the-real-bytes', Storage::disk('spaces')->get('training/videos/lesson-1.mp4'));
    }

    public function test_a_missing_source_is_reported_rather_than_silently_published(): void
    {
        $asset = $this->video('lesson-1.mp4');
        Storage::disk('local')->delete('kartra-videos/lesson-1.mp4');

        $this->artisan('training:publish-media', ['--videos' => true])->assertFailed();

        $this->assertNull($asset->refresh()->disk);
    }

    public function test_worksheet_blocks_are_repointed_at_the_published_object(): void
    {
        $this->worksheet('worksheet.pdf');

        $this->artisan('training:publish-media', ['--files' => true])->assertSuccessful();

        Storage::disk('spaces')->assertExists('training/files/worksheet.pdf');

        $block = TrainingContentBlock::where('type', 'download')->first();
        $this->assertSame('spaces', $block->file_disk);
        $this->assertSame('training/files/worksheet.pdf', $block->file_path);
    }

    public function test_a_worksheet_shared_by_two_lessons_repoints_both(): void
    {
        // Kartra attached the same PDF to two lessons under two download ids,
        // so both blocks point at one local file and both must follow it.
        $this->worksheet('shared.pdf');

        $second = TrainingLesson::create([
            'category_id' => $this->lesson->category_id, 'title' => 'Other', 'slug' => 'other',
            'sort_order' => 1, 'is_published' => true,
        ]);

        TrainingContentBlock::create([
            'lesson_id' => $second->id,
            'type'      => 'download',
            'title'     => 'shared.pdf',
            'file_path' => 'kartra-files/shared.pdf',
            'file_disk' => 'local',
            'file_name' => 'shared.pdf',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->artisan('training:publish-media', ['--files' => true])->assertSuccessful();

        $this->assertSame(
            2,
            TrainingContentBlock::where('file_path', 'training/files/shared.pdf')
                ->where('file_disk', 'spaces')->count(),
        );
    }

    public function test_the_limit_stops_after_the_given_number(): void
    {
        foreach (['a.mp4', 'b.mp4', 'c.mp4'] as $name) {
            $this->video($name);
        }

        $this->artisan('training:publish-media', ['--videos' => true, '--limit' => 2])
            ->assertSuccessful();

        $this->assertSame(2, VideoAsset::whereNotNull('storage_path')->count());
    }
}
