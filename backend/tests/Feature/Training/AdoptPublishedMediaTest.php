<?php

namespace Tests\Feature\Training;

use App\Models\KartraFile;
use App\Models\KartraImport;
use App\Models\TrainingCategory;
use App\Models\TrainingContentBlock;
use App\Models\VideoAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Building the library from media that is already in the Space.
 *
 * The 24 GB is uploaded once. Every other environment — production included —
 * attaches its rows to those objects by name rather than holding a copy of its
 * own, which is what keeps the library off the droplet's disk entirely.
 *
 * The matching is the same slug rule the local adoption uses, because the
 * published object keeps the filename the download gave it.
 */
class AdoptPublishedMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('spaces');

        config([
            'training.disk'         => 'spaces',
            'training.paths.videos' => 'training/videos',
            'training.paths.files'  => 'training/files',
        ]);
    }

    private function publishedVideo(string $title, string $filename): KartraImport
    {
        Storage::disk('spaces')->put("training/videos/{$filename}", 'remote-video-bytes');

        return KartraImport::create([
            'kartra_type'      => 'lesson',
            'kartra_title'     => $title,
            'kartra_video_url' => 'https://cdn.example/' . $filename,
            'status'           => 'discovered',
        ]);
    }

    public function test_it_attaches_videos_to_objects_already_in_the_space(): void
    {
        // The name the downloader produced: slug of the title, then a row id
        // that means nothing once the table has been rebuilt.
        $import = $this->publishedVideo('Lesson 1: The Vision of Your-Future', 'lesson-1-the-vision-of-your-future-2.mp4');

        $this->artisan('training:adopt-media', ['--disk' => 'spaces'])->assertSuccessful();

        $asset = VideoAsset::firstOrFail();

        $this->assertSame('spaces', $asset->disk);
        $this->assertSame('training/videos/lesson-1-the-vision-of-your-future-2.mp4', $asset->storage_path);
        $this->assertNotNull($asset->published_at);
        $this->assertTrue($asset->isPublished());

        // No local copy is claimed, because there is not one.
        $this->assertNull($asset->local_path);

        $this->assertSame($asset->id, $import->refresh()->video_asset_id);
    }

    public function test_the_served_path_resolves_to_the_remote_object(): void
    {
        $this->publishedVideo('Lesson 1', 'lesson-1-2.mp4');

        $this->artisan('training:adopt-media', ['--disk' => 'spaces'])->assertSuccessful();

        $asset = VideoAsset::firstOrFail();

        $this->assertSame('spaces', $asset->mediaDisk());
        $this->assertSame('training/videos/lesson-1-2.mp4', $asset->mediaPath());
        $this->assertTrue($asset->isPlayable());
    }

    public function test_worksheets_attach_and_the_seeder_points_blocks_at_the_space(): void
    {
        Storage::disk('spaces')->put('training/files/ah-wisdom-worksheetpdf-1.pdf', '%PDF-1.4');

        $module = KartraImport::create([
            'kartra_type' => 'module', 'kartra_title' => 'Module', 'kartra_order' => 0, 'status' => 'discovered',
        ]);

        $lesson = KartraImport::create([
            'kartra_type' => 'lesson', 'kartra_title' => 'Lesson', 'kartra_order' => 0,
            'parent_id' => $module->id, 'status' => 'discovered',
        ]);

        KartraFile::create([
            'kartra_import_id' => $lesson->id,
            'display_name'     => 'AH Wisdom Worksheet.pdf',
            'status'           => 'pending',
        ]);

        $this->artisan('training:adopt-media', ['--disk' => 'spaces'])->assertSuccessful();

        $file = KartraFile::firstOrFail();
        $this->assertSame('spaces', $file->disk);
        $this->assertSame('training/files/ah-wisdom-worksheetpdf-1.pdf', $file->local_path);

        $this->artisan('kartra:seed-training', ['--fresh' => true])->assertSuccessful();

        $block = TrainingContentBlock::where('type', 'download')->firstOrFail();

        // The block must name the Space, not the local disk it would default to.
        $this->assertSame('spaces', $block->file_disk);
        $this->assertSame('training/files/ah-wisdom-worksheetpdf-1.pdf', $block->file_path);
        $this->assertSame('AH Wisdom Worksheet.pdf', $block->file_name);
    }

    public function test_an_empty_bucket_fails_rather_than_building_an_empty_library(): void
    {
        $this->publishedVideo('Lesson 1', 'lesson-1-2.mp4');

        // Wrong prefix: the objects are there but not where we are looking.
        config(['training.paths.videos' => 'nope/videos', 'training.paths.files' => 'nope/files']);

        // Silently succeeding here would leave production with a library of
        // lessons that play nothing, which is worse than a failed command.
        $this->artisan('training:adopt-media', ['--disk' => 'spaces'])->assertFailed();

        $this->assertSame(0, VideoAsset::count());
    }

    public function test_running_it_twice_attaches_nothing_new(): void
    {
        $this->publishedVideo('Lesson 1', 'lesson-1-2.mp4');

        $this->artisan('training:adopt-media', ['--disk' => 'spaces'])->assertSuccessful();
        $this->artisan('training:adopt-media', ['--disk' => 'spaces'])->assertSuccessful();

        $this->assertSame(1, VideoAsset::count());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->publishedVideo('Lesson 1', 'lesson-1-2.mp4');

        $this->artisan('training:adopt-media', ['--disk' => 'spaces', '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(0, VideoAsset::count());
    }
}
