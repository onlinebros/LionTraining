<?php

namespace Tests\Feature\LiveLinks;

use App\Models\LiveLink;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * q3.life/live-zoom and friends: the redirect a visitor follows, and the admin
 * page that decides where it goes.
 */
class LiveLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            [Role::FREE_MEMBER, 'Free Member', false, 1],
            [Role::SUPPORT_ADMIN, 'Support Admin', true, 8],
            [Role::SUPER_ADMIN, 'Super Admin', true, 9],
        ] as [$name, $display, $isAdmin, $level]) {
            Role::create(['name' => $name, 'display_name' => $display, 'is_admin' => $isAdmin, 'level' => $level]);
        }
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::findByName($role)->id]);
    }

    public function test_the_holding_spots_exist_empty_after_migrating(): void
    {
        $this->assertEqualsCanonicalizing(
            ['live-zoom', 'live-call', 'live-training', 'live-event', 'live-webinar'],
            LiveLink::pluck('slug')->all(),
        );
        $this->assertSame(0, LiveLink::whereNotNull('destination_url')->count());
    }

    public function test_an_empty_spot_shows_the_holding_page_uncached(): void
    {
        $this->get('/live-zoom')
            ->assertOk()
            ->assertSee('Nothing live here right now')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_a_filled_spot_redirects_uncached_and_counts_the_click(): void
    {
        LiveLink::where('slug', 'live-zoom')->update(['destination_url' => 'https://zoom.us/j/123']);

        $this->get('/live-zoom')
            ->assertRedirect('https://zoom.us/j/123')
            ->assertStatus(302)
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

        $link = LiveLink::where('slug', 'live-zoom')->first();
        $this->assertSame(1, $link->clicks);
        $this->assertNotNull($link->last_clicked_at);
    }

    public function test_a_switched_off_spot_holds_even_with_a_destination(): void
    {
        LiveLink::where('slug', 'live-call')->update(['destination_url' => 'https://zoom.us/j/1', 'is_active' => false]);

        $this->get('/live-call')->assertOk()->assertSee('Nothing live here right now');
    }

    public function test_unknown_live_paths_are_404_and_other_paths_are_not_captured(): void
    {
        $this->get('/live-nothing-here')->assertNotFound();
        $this->get('/')->assertOk();
    }

    public function test_super_admin_points_a_link_somewhere_new(): void
    {
        config(['live_links.public_base_url' => 'https://q3.life']);
        $admin = $this->user(Role::SUPER_ADMIN);
        $link  = LiveLink::where('slug', 'live-zoom')->first();

        $this->actingAs($admin)->get(route('admin.live-links.index'))
            ->assertOk()->assertSee('https://q3.life/live-zoom');

        $this->actingAs($admin)->put(route('admin.live-links.update', $link), [
            'label' => 'Tuesday Zoom', 'destination_url' => 'https://zoom.us/j/999', 'is_active' => '1',
        ])->assertRedirect()->assertSessionHas('success');

        $link->refresh();
        $this->assertSame('https://zoom.us/j/999', $link->destination_url);
        $this->assertSame('Tuesday Zoom', $link->label);
        $this->assertSame($admin->id, $link->updated_by);
        $this->get('/live-zoom')->assertRedirect('https://zoom.us/j/999');
    }

    public function test_destinations_must_be_https(): void
    {
        $admin = $this->user(Role::SUPER_ADMIN);
        $link  = LiveLink::where('slug', 'live-zoom')->first();

        foreach (['http://zoom.us/j/1', 'javascript:alert(1)', 'not a url'] as $bad) {
            $this->actingAs($admin)->put(route('admin.live-links.update', $link), [
                'label' => 'x', 'destination_url' => $bad, 'is_active' => '1',
            ])->assertSessionHasErrors('destination_url');
        }
        $this->assertNull($link->fresh()->destination_url);
    }

    public function test_adding_a_link_requires_the_prefix(): void
    {
        $admin = $this->user(Role::SUPER_ADMIN);

        $this->actingAs($admin)->post(route('admin.live-links.store'), ['slug' => 'zoom', 'label' => 'Z'])
            ->assertSessionHasErrors('slug');
        $this->actingAs($admin)->post(route('admin.live-links.store'), ['slug' => 'live-zoom', 'label' => 'Z'])
            ->assertSessionHasErrors('slug');

        $this->actingAs($admin)->post(route('admin.live-links.store'), [
            'slug' => 'live-q-and-a', 'label' => 'Live Q&A', 'destination_url' => 'https://example.com/qa',
        ])->assertSessionHasNoErrors();

        $this->get('/live-q-and-a')->assertRedirect('https://example.com/qa');
    }

    public function test_support_admins_and_members_cannot_edit(): void
    {
        $link = LiveLink::first();

        foreach ([Role::SUPPORT_ADMIN, Role::FREE_MEMBER] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->get(route('admin.live-links.index'))->assertStatus(403);
            $this->actingAs($user)->put(route('admin.live-links.update', $link), [
                'label' => 'x', 'destination_url' => 'https://evil.example',
            ]);
        }
        $this->assertNull($link->fresh()->destination_url);
    }
}
