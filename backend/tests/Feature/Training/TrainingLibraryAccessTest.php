<?php

namespace Tests\Feature\Training;

use App\Models\Role;
use App\Models\Subscription;
use App\Models\TrainingCategory;
use App\Models\TrainingContentBlock;
use App\Models\TrainingLesson;
use App\Models\User;
use App\Models\VideoAsset;
use App\Support\TrainingAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Who can reach the training library, and who can reach its bytes.
 *
 * The library is the product. Everything here is about the difference between
 * a page being hidden and a video being unreachable: hiding the page is
 * presentation, and if the stream URL still serves the file then none of it
 * counts. So every case that checks a page also checks the media route behind
 * it.
 */
class TrainingLibraryAccessTest extends TestCase
{
    use RefreshDatabase;

    private TrainingCategory $openCategory;
    private TrainingCategory $lockedCategory;
    private TrainingContentBlock $openVideo;
    private TrainingContentBlock $lockedVideo;
    private TrainingContentBlock $worksheet;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            [Role::FREE_MEMBER, 'Free Member', false, 1],
            [Role::PAID_MEMBER, 'Paid Member', false, 2],
            [Role::SUPER_ADMIN, 'Super Admin', true, 99],
        ] as [$name, $display, $isAdmin, $level]) {
            Role::create(['name' => $name, 'display_name' => $display, 'is_admin' => $isAdmin, 'level' => $level]);
        }

        config([
            'prelaunch.enabled'  => false,
            'stripe.grace_days'  => 7,
            'training.visibility' => TrainingAccess::ADMIN_ONLY,
            'filesystems.disks.local.root' => storage_path('framework/testing/training'),
        ]);

        Storage::fake('local');

        // Module 1: open immediately.
        $this->openCategory = TrainingCategory::create([
            'name' => 'Finding The Ancient-Path', 'slug' => 'ancient-path',
            'sort_order' => 0, 'is_active' => true,
        ]);

        // Module 4: three months into the drip.
        $this->lockedCategory = TrainingCategory::create([
            'name' => 'Learn To SEE', 'slug' => 'learn-to-see',
            'sort_order' => 3, 'is_active' => true,
            'release_delay' => 3, 'release_delay_unit' => 'months',
        ]);

        $this->openVideo   = $this->lessonWithVideo($this->openCategory, 'Lesson 1', 'open.mp4');
        $this->lockedVideo = $this->lessonWithVideo($this->lockedCategory, 'Lesson 20', 'locked.mp4');

        Storage::disk('local')->put('training/files/worksheet.pdf', '%PDF-1.4 worksheet');

        $this->worksheet = TrainingContentBlock::create([
            'lesson_id' => $this->openVideo->lesson_id,
            'type'      => 'download',
            'title'     => 'AH Wisdom Worksheet.pdf',
            'file_path' => 'training/files/worksheet.pdf',
            'file_disk' => 'local',
            'file_name' => 'AH Wisdom Worksheet.pdf',
            'sort_order' => 1,
            'is_active' => true,
        ]);
    }

    private function lessonWithVideo(TrainingCategory $category, string $title, string $filename): TrainingContentBlock
    {
        // 2 KB of recognisable bytes, so a range response can be checked
        // against the exact slice it should have returned.
        $body = str_repeat('ABCDEFGH', 256);
        Storage::disk('local')->put("training/videos/{$filename}", $body);

        $lesson = TrainingLesson::create([
            'category_id' => $category->id,
            'title'       => $title,
            'slug'        => TrainingLesson::uniqueSlug($title),
            'sort_order'  => 0,
            'is_published' => true,
        ]);

        $asset = VideoAsset::create([
            'title'        => $title,
            'source'       => 'kartra',
            'local_path'   => "training/videos/{$filename}",
            'local_filename' => $filename,
            'file_size'    => strlen($body),
            'mime_type'    => 'video/mp4',
            'vimeo_status' => 'self_hosted',
        ]);

        return TrainingContentBlock::create([
            'lesson_id'      => $lesson->id,
            'type'           => 'video',
            'video_asset_id' => $asset->id,
            'sort_order'     => 0,
            'is_active'      => true,
        ]);
    }

    private function member(array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'role_id' => Role::findByName(Role::PAID_MEMBER)->id,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role_id' => Role::findByName(Role::SUPER_ADMIN)->id]);
    }

    /** A paying member whose membership started `$monthsAgo` months ago. */
    private function subscribedMember(int $monthsAgo = 0): User
    {
        $user = $this->member();

        Subscription::create([
            'user_id'                  => $user->id,
            'provider_subscription_id' => 'sub_' . uniqid(),
            'status'                   => Subscription::STATUS_ACTIVE,
            'current_period_start'     => now()->subMonths($monthsAgo),
            'current_period_end'       => now()->addMonth(),
        ]);

        return $user->refresh();
    }

    private function openLibrary(): void
    {
        TrainingAccess::open();
    }

    // ── The library is hidden until it is released ────────────────────────────

    public function test_members_cannot_see_the_library_while_it_is_admin_only(): void
    {
        $this->actingAs($this->subscribedMember());

        foreach ([
            route('member.training'),
            route('member.training.category', $this->openCategory->slug),
            route('member.training.lesson', $this->openVideo->lesson->slug),
        ] as $url) {
            $this->get($url)->assertNotFound();
        }
    }

    public function test_no_media_is_served_while_the_library_is_admin_only(): void
    {
        $this->actingAs($this->subscribedMember());

        // The point of the whole exercise: the pages 404, and so do the bytes.
        $this->get(route('member.training.video', $this->openVideo))->assertNotFound();
        $this->get(route('member.training.download', $this->worksheet))->assertNotFound();
    }

    public function test_an_admin_previews_the_hidden_library(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('member.training'))->assertOk();
        $this->get(route('member.training.lesson', $this->openVideo->lesson->slug))->assertOk();
        $this->get(route('member.training.video', $this->openVideo))->assertOk();
    }

    public function test_an_admin_previews_modules_that_have_not_released(): void
    {
        $this->actingAs($this->admin());

        // No drip date can have passed for an admin with no subscription, yet
        // they must still be able to check the material before it ships.
        $this->get(route('member.training.video', $this->lockedVideo))->assertOk();
    }

    public function test_releasing_the_library_opens_it_to_members(): void
    {
        $this->openLibrary();
        $this->actingAs($this->subscribedMember());

        $this->get(route('member.training'))->assertOk();
        $this->get(route('member.training.video', $this->openVideo))->assertOk();
    }

    // ── Membership ────────────────────────────────────────────────────────────

    public function test_a_guest_gets_no_media(): void
    {
        $this->openLibrary();

        $this->get(route('member.training.video', $this->openVideo))->assertRedirect();
        $this->get(route('member.training.download', $this->worksheet))->assertRedirect();
    }

    public function test_a_member_without_a_live_subscription_gets_no_media(): void
    {
        $this->openLibrary();

        $lapsed = $this->member();
        Subscription::create([
            'user_id'                  => $lapsed->id,
            'provider_subscription_id' => 'sub_' . uniqid(),
            'status'                   => Subscription::STATUS_CANCELED,
            'current_period_end'       => now()->subMonths(2),
        ]);

        $this->actingAs($lapsed->refresh());

        // Redirected to billing rather than served the video they stopped
        // paying for.
        $this->get(route('member.training.video', $this->openVideo))->assertRedirect();
    }

    public function test_a_partner_on_commission_hold_gets_no_media(): void
    {
        $this->openLibrary();

        $waiting = $this->member();
        Subscription::create([
            'user_id'                  => $waiting->id,
            'provider_subscription_id' => 'sub_' . uniqid(),
            'status'                   => Subscription::STATUS_TRIALING,
            'billing_trigger'          => Subscription::TRIGGER_COMMISSION,
            'current_period_end'       => now()->addMonth(),
        ]);

        $this->actingAs($waiting->refresh());

        $this->get(route('member.training.video', $this->openVideo))->assertRedirect();
    }

    // ── The monthly drip ──────────────────────────────────────────────────────

    public function test_a_new_member_cannot_reach_a_module_that_has_not_released(): void
    {
        $this->openLibrary();
        $this->actingAs($this->subscribedMember(monthsAgo: 0));

        $this->get(route('member.training.video', $this->openVideo))->assertOk();
        $this->get(route('member.training.video', $this->lockedVideo))->assertNotFound();
    }

    public function test_the_module_opens_once_enough_months_have_passed(): void
    {
        $this->openLibrary();
        $this->actingAs($this->subscribedMember(monthsAgo: 4));

        $this->get(route('member.training.video', $this->lockedVideo))->assertOk();
    }

    public function test_the_drip_counts_from_the_paid_membership_not_the_signup(): void
    {
        $this->openLibrary();

        // Signed up a year ago, but only started paying today. The clock runs
        // from the payment, so month four has not arrived.
        $user = $this->member();
        $user->forceFill(['created_at' => now()->subYear()])->save();

        Subscription::create([
            'user_id'                  => $user->id,
            'provider_subscription_id' => 'sub_' . uniqid(),
            'status'                   => Subscription::STATUS_ACTIVE,
            'current_period_start'     => now(),
            'current_period_end'       => now()->addMonth(),
        ]);

        $this->actingAs($user->refresh());

        $this->get(route('member.training.video', $this->lockedVideo))->assertNotFound();
    }

    public function test_a_locked_parent_category_locks_the_lessons_beneath_it(): void
    {
        $this->openLibrary();

        // A lesson in an open sub-category whose parent is still on the drip.
        $child = TrainingCategory::create([
            'name' => 'Sub Module', 'slug' => 'sub-module',
            'parent_id' => $this->lockedCategory->id,
            'sort_order' => 0, 'is_active' => true,
        ]);

        $block = $this->lessonWithVideo($child, 'Nested Lesson', 'nested.mp4');

        $this->actingAs($this->subscribedMember(monthsAgo: 0));

        // The lesson's own category has no delay, so only walking up the tree
        // catches this one.
        $this->get(route('member.training.video', $block))->assertNotFound();
    }

    // ── Streaming ─────────────────────────────────────────────────────────────

    public function test_a_video_is_streamed_with_range_support(): void
    {
        $this->openLibrary();
        $this->actingAs($this->subscribedMember());

        $full = $this->get(route('member.training.video', $this->openVideo));

        $full->assertOk();
        $full->assertHeader('Accept-Ranges', 'bytes');
        // Never cacheable: Cloudflare sits in front of this and a cached video
        // is one served without any access check at all.
        $this->assertStringContainsString('no-store', $full->headers->get('Cache-Control'));
    }

    public function test_a_range_request_returns_exactly_that_slice(): void
    {
        $this->openLibrary();
        $this->actingAs($this->subscribedMember());

        $response = $this->withHeaders(['Range' => 'bytes=8-15'])
            ->get(route('member.training.video', $this->openVideo));

        $response->assertStatus(206);
        $response->assertHeader('Content-Range', 'bytes 8-15/2048');
        $response->assertHeader('Content-Length', '8');

        // Bytes 8..15 of 'ABCDEFGH' repeated is the second copy of it.
        $this->assertSame('ABCDEFGH', $response->streamedContent());
    }

    public function test_an_open_ended_range_runs_to_the_end_of_the_file(): void
    {
        $this->openLibrary();
        $this->actingAs($this->subscribedMember());

        $response = $this->withHeaders(['Range' => 'bytes=2040-'])
            ->get(route('member.training.video', $this->openVideo));

        $response->assertStatus(206);
        $response->assertHeader('Content-Range', 'bytes 2040-2047/2048');
        $this->assertSame('ABCDEFGH', $response->streamedContent());
    }

    public function test_a_suffix_range_returns_the_tail(): void
    {
        $this->openLibrary();
        $this->actingAs($this->subscribedMember());

        // How a player reads the moov atom of an MP4 not prepared for
        // streaming. Getting this wrong makes such videos unplayable.
        $response = $this->withHeaders(['Range' => 'bytes=-8'])
            ->get(route('member.training.video', $this->openVideo));

        $response->assertStatus(206);
        $response->assertHeader('Content-Range', 'bytes 2040-2047/2048');
    }

    public function test_an_unsatisfiable_range_is_refused(): void
    {
        $this->openLibrary();
        $this->actingAs($this->subscribedMember());

        $this->withHeaders(['Range' => 'bytes=9999-'])
            ->get(route('member.training.video', $this->openVideo))
            ->assertStatus(416);
    }

    // ── Worksheets ────────────────────────────────────────────────────────────

    public function test_a_worksheet_downloads_for_a_member_who_may_see_the_lesson(): void
    {
        $this->openLibrary();
        $this->actingAs($this->subscribedMember());

        $this->get(route('member.training.download', $this->worksheet))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="AH Wisdom Worksheet.pdf"');
    }

    public function test_an_inactive_block_serves_nothing(): void
    {
        $this->openLibrary();
        $this->openVideo->update(['is_active' => false]);

        $this->actingAs($this->subscribedMember());

        $this->get(route('member.training.video', $this->openVideo))->assertNotFound();
    }

    public function test_an_unpublished_lesson_serves_nothing(): void
    {
        $this->openLibrary();
        $this->openVideo->lesson->update(['is_published' => false]);

        $this->actingAs($this->subscribedMember());

        $this->get(route('member.training.video', $this->openVideo))->assertNotFound();
    }
}
