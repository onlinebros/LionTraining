<?php

namespace Tests\Feature\Theme;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Q3 theme is wired through the layouts rather than any single view, so
 * the failure mode is silent: drop the body class or the stylesheet include
 * and every page renders in the stock Cuba purple with nothing else breaking.
 * These assertions pin the wiring, not the design.
 */
class Q3ThemeTest extends TestCase
{
    use RefreshDatabase;

    private function member(): User
    {
        $role = Role::create([
            'name' => Role::FREE_MEMBER,
            'display_name' => 'Free Member',
            'is_admin' => false,
            'level' => 1,
        ]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_member_shell_loads_the_theme_after_the_template_stylesheets(): void
    {
        $html = $this->actingAs($this->member())
            ->get(route('member.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('class="dark-only q3-theme"', $html);

        // Order is the whole game: the theme layer only wins the cascade if it
        // is included after custom.css, and the hand-written rules only win
        // over the generated remap if they come last.
        $custom  = strpos($html, 'assets/css/custom.css');
        $palette = strpos($html, 'assets/css/q3-palette-map.css');
        $theme   = strpos($html, 'assets/css/q3-theme.css');

        $this->assertNotFalse($palette, 'q3-palette-map.css is not loaded.');
        $this->assertNotFalse($theme, 'q3-theme.css is not loaded.');
        $this->assertLessThan($palette, $custom, 'The theme must load after custom.css.');
        $this->assertLessThan($theme, $palette, 'q3-theme.css must load after the generated palette map.');
    }

    public function test_member_shell_carries_the_q3_brand_and_pwa_icons(): void
    {
        $html = $this->actingAs($this->member())
            ->get(route('member.dashboard'))
            ->assertOk()
            ->getContent();

        // The mark appears twice: sidebar panel and the top bar that survives
        // the mobile breakpoint. Both are cache-busted: the logo files are
        // rebuilt in place at a stable path (scripts/build-q3-logo-assets.py),
        // so without a version a browser that cached the old mark keeps
        // serving it and a rebrand looks like it never shipped.
        $this->assertSame(2, substr_count($html, 'logo/q3_logo-sm.png?v='));

        $this->assertStringContainsString('assets/q3.webmanifest', $html);

        // The label under the installed app's icon: iOS reads the meta,
        // Android the manifest's short_name. They must agree.
        $this->assertStringContainsString('<meta name="apple-mobile-web-app-title" content="Quantum Solution">', $html);
        $manifest = json_decode(file_get_contents(base_path('../assets/q3.webmanifest')), true);
        $this->assertSame('Quantum Solution', $manifest['short_name']);
        $this->assertStringContainsString('logo/q3-apple-touch-icon-180.png?v=', $html);
        $this->assertStringContainsString('<meta name="theme-color" content="#050505">', $html);

        // The Cuba light/dark switch would strip .dark-only and half-unstyle
        // the UI, so it must not be rendered.
        $this->assertStringNotContainsString('<div class="mode">', $html);
    }

    public function test_home_screen_icons_are_backed_with_black(): void
    {
        // Phones fill a transparent home-screen icon with white, so a
        // transparent rebuild silently puts the gold mark on a white tile.
        $manifest = json_decode(file_get_contents(base_path('../assets/q3.webmanifest')), true);

        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));

        $icons = array_map(fn (array $icon) => base_path('../assets/'.$icon['src']), $manifest['icons']);
        $icons[] = base_path('../assets/images/logo/q3-apple-touch-icon-180.png');

        foreach ($icons as $path) {
            $this->assertFileExists($path);
        }

        if (! function_exists('imagecreatefrompng')) {
            $this->markTestSkipped('GD is needed to inspect icon pixels.');
        }

        foreach ($icons as $path) {
            $image = imagecreatefrompng($path);
            $corner = imagecolorsforindex($image, imagecolorat($image, 0, 0));
            $this->assertSame(0, $corner['alpha'], basename($path).' has a transparent background.');
        }
    }

    public function test_login_screen_uses_the_dark_auth_shell(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('class="dark-only q3-theme q3-auth"', $html);
        $this->assertStringContainsString('q3-auth-card', $html);
        $this->assertStringContainsString('logo/q3_logo-sm.png?v=', $html);
        $this->assertStringContainsString('assets/css/q3-theme.css', $html);
    }

    public function test_theme_stylesheets_are_present_and_token_driven(): void
    {
        $theme = file_get_contents(base_path('../assets/css/q3-theme.css'));

        $this->assertStringContainsString('--q3-gold:', $theme);
        $this->assertStringContainsString('--q3-sidebar:', $theme);
        $this->assertStringContainsString('--q3-surface:', $theme);

        $this->assertFileExists(base_path('../assets/css/q3-palette-map.css'));
        $this->assertFileExists(base_path('../assets/images/logo/q3_logo-sm.png'));
        $this->assertFileExists(base_path('../assets/q3.webmanifest'));
    }
}
