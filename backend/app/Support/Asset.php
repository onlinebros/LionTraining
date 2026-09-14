<?php

namespace App\Support;

/**
 * Cache-busted asset URLs.
 *
 * The stylesheets are edited in place at a stable path, so a browser that has
 * seen `q3-theme.css` once will keep serving its copy back — a theme change
 * then looks like it did not deploy, on exactly the machines least likely to
 * think of a hard reload. Appending the file's mtime gives each revision its
 * own URL while keeping the long cache lifetime for revisions that have not
 * changed.
 *
 * This project has no Vite/Mix manifest for these files — they are hand-written
 * CSS served straight out of public/ — so the mtime is the version.
 */
class Asset
{
    /** @var array<string,string> Per-request memo; these are stat calls in a loop otherwise. */
    private static array $resolved = [];

    /**
     * @param  string  $path  Public-relative, e.g. 'assets/css/q3-theme.css'.
     */
    public static function v(string $path): string
    {
        if (isset(self::$resolved[$path])) {
            return self::$resolved[$path];
        }

        $url  = asset($path);
        $full = public_path($path);

        // A missing file still returns a usable URL. A 404 on a stylesheet is a
        // visible bug; a fatal error rendering the <head> is a white page.
        if (is_file($full) && ($stamp = @filemtime($full)) !== false) {
            $url .= (str_contains($url, '?') ? '&' : '?').'v='.$stamp;
        }

        return self::$resolved[$path] = $url;
    }
}
