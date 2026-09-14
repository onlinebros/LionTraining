<?php

namespace Tests\Feature\Theme;

use Tests\TestCase;

/**
 * Pins two theme fixes that nothing else would notice being undone.
 *
 * Both failures are silent. Deleting the inheritance guard doesn't break a page,
 * it just turns text dark again. An unversioned stylesheet doesn't error, it
 * just keeps serving yesterday's CSS from Cloudflare for four hours. Neither
 * would fail a normal test, so these check the source directly.
 */
class TextColourGuardTest extends TestCase
{
    /**
     * Cuba's style.css colours p, li, dd and dt DIRECTLY (#404040). A direct
     * declaration beats any inherited colour, so customer-facing text rendered at
     * about 1.67:1 on the dark theme until q3-theme.css handed those elements
     * back to their containers. It must cover every body shell the app renders.
     */
    public function test_the_theme_hands_text_elements_back_to_their_containers(): void
    {
        $css = file_get_contents(base_path('public/assets/css/q3-theme.css'));

        foreach (['q3-theme', 'q3-auth', 'q3-storefront'] as $shell) {
            foreach (['p', 'li', 'dd', 'dt'] as $element) {
                $this->assertMatchesRegularExpression(
                    '/\.'.preg_quote($shell, '/').'\s+'.$element.'\b[^{]*\{[^}]*color:\s*inherit/s',
                    $css,
                    "q3-theme.css must reset .{$shell} {$element} to color: inherit, or the template's "
                    .'#404040 comes back and text on the dark theme becomes unreadable.'
                );
            }
        }
    }

    /**
     * The site sits behind Cloudflare, which caches /assets for four hours. A
     * theme stylesheet linked without Asset::v() keeps serving the old copy
     * after every change — which is how a CSS fix appears not to have deployed.
     * Checks every view, so a future page with its own <head> is caught too.
     */
    public function test_theme_stylesheets_are_always_version_stamped(): void
    {
        $views     = resource_path('views');
        $offenders = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($views));

        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            preg_match_all(
                '/href="\{\{\s*([^}]*?)\(\s*\'assets\/css\/q3-[\w-]+\.css\'\s*\)\s*\}\}"/',
                file_get_contents($file->getPathname()),
                $matches
            );

            foreach ($matches[1] as $helper) {
                if (! str_contains($helper, 'Asset::v')) {
                    $offenders[] = ltrim(str_replace($views, '', $file->getPathname()), '/');
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($offenders)),
            'These views link a Q3 stylesheet without \App\Support\Asset::v(); Cloudflare will serve them stale.'
        );
    }
}
