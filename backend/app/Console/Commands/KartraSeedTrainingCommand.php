<?php

namespace App\Console\Commands;

use App\Models\KartraImport;
use App\Models\TrainingCategory;
use App\Models\TrainingContentBlock;
use App\Models\TrainingLesson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class KartraSeedTrainingCommand extends Command
{
    protected $signature = 'kartra:seed-training
                            {--fresh : Clear existing training data before seeding}';

    protected $description = 'Populate training categories/lessons/blocks from imported Kartra content';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            $this->clearTrainingData();
        } elseif (TrainingCategory::count() > 0) {
            if (!$this->confirm('Training data already exists. Clear and rebuild?', true)) {
                $this->info('Aborted.');
                return self::SUCCESS;
            }
            $this->clearTrainingData();
        }

        $modules = KartraImport::whereNull('parent_id')
            ->where('kartra_type', 'module')
            ->orderBy('kartra_order')
            ->with([
                'children' => fn($q) => $q->orderBy('kartra_order'),
                'children.files' => fn($q) => $q->where('status', 'downloaded'),
                'children.videoAsset',
            ])
            ->get();

        if ($modules->isEmpty()) {
            $this->error('No kartra modules found. Run kartra:import first.');
            return self::FAILURE;
        }

        $this->info("Building training content from {$modules->count()} modules…");

        $catCount   = 0;
        $lesCount   = 0;
        $blockCount = 0;

        foreach ($modules as $module) {
            $category = $this->makeCategory($module, $catCount);
            $catCount++;

            foreach ($module->children as $kartraLesson) {
                $lesson = $this->makeLesson($kartraLesson, $category);
                $lesCount++;
                $blockCount += $this->makeBlocks($kartraLesson, $lesson);
            }
        }

        $this->table(
            ['Categories', 'Lessons', 'Content Blocks'],
            [[$catCount, $lesCount, $blockCount]]
        );

        $this->info('Done. Visit /member/training to preview the result.');
        return self::SUCCESS;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function clearTrainingData(): void
    {
        $this->warn('Clearing existing training data…');

        // Driver-agnostic: SET FOREIGN_KEY_CHECKS is MySQL-only and errors on
        // Postgres. Schema::withoutForeignKeyConstraints issues the right
        // statement per driver, and the deletes run child-first regardless.
        Schema::withoutForeignKeyConstraints(function () {
            TrainingContentBlock::truncate();
            TrainingLesson::truncate();
            TrainingCategory::truncate();
        });
    }

    private function makeCategory(KartraImport $module, int $index): TrainingCategory
    {
        $name = $module->kartra_title;

        return TrainingCategory::create([
            'name'        => $name,
            'slug'        => TrainingCategory::uniqueSlug($name),
            'description' => null,
            'sort_order'  => $index,
            'is_active'   => true,
        ]);
    }

    private function makeLesson(KartraImport $kartraLesson, TrainingCategory $category): TrainingLesson
    {
        $title = $kartraLesson->kartra_title ?: "Lesson #{$kartraLesson->id}";

        return TrainingLesson::create([
            'category_id'  => $category->id,
            'title'        => $title,
            'slug'         => TrainingLesson::uniqueSlug($title),
            'description'  => null,
            'sort_order'   => (int) $kartraLesson->kartra_order,
            'is_published' => true,
            'is_featured'  => false,
        ]);
    }

    private function makeBlocks(KartraImport $kartraLesson, TrainingLesson $lesson): int
    {
        $sort  = 0;
        $count = 0;

        // ── Video block ────────────────────────────────────────────────────────
        // Link to VideoAsset if downloaded; fall back to raw CloudFront URL
        if ($kartraLesson->video_asset_id || $kartraLesson->kartra_video_url) {
            TrainingContentBlock::create([
                'lesson_id'      => $lesson->id,
                'type'           => 'video',
                'title'          => null,
                'video_asset_id' => $kartraLesson->video_asset_id,
                'video_url'      => $kartraLesson->video_asset_id ? null : $kartraLesson->kartra_video_url,
                'video_provider' => $kartraLesson->video_asset_id ? null : 'file',
                'sort_order'     => $sort++,
                'is_active'      => true,
            ]);
            $count++;
        }

        // ── Text block ─────────────────────────────────────────────────────────
        $html = trim((string) $kartraLesson->page_html);
        if ($html) {
            TrainingContentBlock::create([
                'lesson_id'  => $lesson->id,
                'type'       => 'text',
                'title'      => null,
                'body'       => $html,
                'sort_order' => $sort++,
                'is_active'  => true,
            ]);
            $count++;
        }

        // ── Download blocks ────────────────────────────────────────────────────
        foreach ($kartraLesson->files as $file) {
            $displayName = $file->display_name ?: $file->original_filename ?: 'Download';

            TrainingContentBlock::create([
                'lesson_id'  => $lesson->id,
                'type'       => 'download',
                'title'      => $displayName,
                'file_path'  => $file->local_path,
                'file_name'  => $file->original_filename ?: $file->local_filename,
                'file_size'  => $file->file_size,
                'file_mime'  => $file->mime_type,
                'sort_order' => $sort++,
                'is_active'  => true,
            ]);
            $count++;
        }

        return $count;
    }
}
