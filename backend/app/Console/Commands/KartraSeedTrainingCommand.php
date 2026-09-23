<?php

namespace App\Console\Commands;

use App\Models\KartraImport;
use App\Models\TrainingCategory;
use App\Models\TrainingContentBlock;
use App\Models\TrainingLesson;
use Illuminate\Console\Command;

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

        // Counts only the modules that take part in the monthly drip, so the
        // reference sections sitting between them do not push a teaching module
        // a month further out.
        $teachingIndex = 0;
        $schedule      = [];

        foreach ($modules as $module) {
            $alwaysOpen = $this->isAlwaysOpen($module);

            $category = $this->makeCategory(
                $module,
                $catCount,
                $alwaysOpen ? null : $teachingIndex,
            );

            $schedule[] = [
                $category->name,
                $alwaysOpen
                    ? 'open'
                    : ($teachingIndex === 0 ? 'month 1' : 'month ' . ($teachingIndex + 1)),
                $module->children->count(),
            ];

            if (! $alwaysOpen) {
                $teachingIndex++;
            }

            $catCount++;

            foreach ($module->children as $kartraLesson) {
                $lesson = $this->makeLesson($kartraLesson, $category);
                $lesCount++;
                $blockCount += $this->makeBlocks($kartraLesson, $lesson);
            }
        }

        $this->newLine();
        $this->line('Release schedule, counted from the start of a paid membership:');
        $this->table(['Module', 'Opens', 'Lessons'], $schedule);

        $this->table(
            ['Categories', 'Lessons', 'Content Blocks'],
            [[$catCount, $lesCount, $blockCount]]
        );

        $this->info('Done. Visit /member/training to preview the result.');
        return self::SUCCESS;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Drop the built library, leaving the Kartra import records alone.
     *
     * This used to call truncate(). On Postgres that issues
     * `TRUNCATE ... RESTART IDENTITY CASCADE`, and CASCADE does not stop at the
     * table you named — it follows every foreign key pointing at it. kartra_imports
     * references training_categories, training_lessons and training_content_blocks,
     * so `--fresh` silently emptied the entire import: 186 scraped records and
     * their links to 124 videos, gone, with the seeder then reporting "no kartra
     * modules found" as though the import had never been run.
     *
     * Deletes do not cascade. Run child-first they need no constraint games at
     * all, and the import — which is the expensive thing to rebuild — survives.
     */
    private function clearTrainingData(): void
    {
        $this->warn('Clearing existing training data…');

        // Release the import's references first so the deletes below are not
        // blocked by them. The import rows themselves are kept.
        KartraImport::query()->update([
            'local_category_id'      => null,
            'local_lesson_id'        => null,
            'local_content_block_id' => null,
        ]);

        TrainingContentBlock::query()->delete();
        TrainingLesson::query()->delete();
        TrainingCategory::query()->delete();
    }

    /**
     * One Kartra module becomes one training category, carrying the release
     * delay its position in the course earns it.
     *
     * $teachingIndex counts only the numbered teaching modules, so the
     * reference sections that sit among them — webinar replays, Media Center,
     * Science — do not consume a month. It is passed as null for those, which
     * leaves them open from day one.
     */
    private function makeCategory(KartraImport $module, int $index, ?int $teachingIndex): TrainingCategory
    {
        $name = $module->kartra_title;

        $step = (int) config('training.drip.step', 1);

        return TrainingCategory::create([
            'name'        => $name,
            'slug'        => TrainingCategory::uniqueSlug($name),
            'description' => null,
            'sort_order'  => $index,
            'is_active'   => true,
            // Month 0 is "available now": a zero delay is stored as null so the
            // release helpers treat it as immediate rather than as a delay of
            // no length.
            'release_delay'      => $teachingIndex === null || $teachingIndex === 0
                ? null
                : $teachingIndex * $step,
            'release_delay_unit' => config('training.drip.unit', 'months'),
        ]);
    }

    /**
     * The name a member's browser should save a worksheet under.
     *
     * Three candidates, none of them reliable on its own:
     *
     *  - `original_filename` is usually null, and where Kartra did set it it is
     *    mangled and has no extension ("What_s_The-Problem_-_Worksheet").
     *  - `display_name` is the readable one ("AH Wisdom Worksheet.pdf") but 34
     *    of the 89 are missing the extension.
     *  - `local_path` always has the right extension but the name is a slug
     *    with a row id welded on ("ah-wisdom-worksheetpdf-1.pdf").
     *
     * So: take the readable name, and borrow the extension from the file on
     * disk when it has none. Saving a PDF with no extension is the one outcome
     * that actually breaks for the member opening it.
     */
    private function downloadFilename(\App\Models\KartraFile $file): string
    {
        $name = trim((string) $file->display_name);

        if ($name === '') {
            $name = trim((string) $file->original_filename);
        }

        if ($name === '') {
            return (string) ($file->local_filename ?: basename((string) $file->local_path));
        }

        if (pathinfo($name, PATHINFO_EXTENSION) === '') {
            $ext = pathinfo((string) $file->local_path, PATHINFO_EXTENSION);

            if ($ext !== '') {
                $name .= '.' . $ext;
            }
        }

        return $name;
    }

    /** Is this module open from day one rather than part of the monthly drip? */
    private function isAlwaysOpen(KartraImport $module): bool
    {
        $open = (array) config('training.drip.always_open', []);

        foreach ($open as $title) {
            if (strcasecmp(trim($title), trim((string) $module->kartra_title)) === 0) {
                return true;
            }
        }

        return false;
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
                // Wherever the file record says its bytes are. That is the
                // local private disk for a fresh Kartra download, or the Space
                // when the library was adopted from one already published.
                'file_disk'  => $file->diskName(),
                // What the member's browser saves it as.
                'file_name'  => $this->downloadFilename($file),
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
