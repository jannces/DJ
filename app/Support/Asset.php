<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * A stylesheet or script URL that changes when the file does.
 *
 * app.css was linked bare, so every browser that had ever loaded the system
 * kept serving its cached copy: a CSS change reached nobody until they thought
 * to hard-refresh, and on a LAN install nobody thinks to. Appending the file's
 * modification time makes it a different URL the moment the file changes, so
 * the browser fetches it once and then caches it properly until the next
 * change.
 *
 * This installation has no build step -- the files are edited in place and
 * served by XAMPP -- so there is no manifest to read the version from.
 */
class Asset
{
    public static function url(string $path): string
    {
        // One stat per file per request would be harmless, but the layout is
        // on every page and the answer only changes when someone edits the
        // file, so it is worth remembering.
        $version = Cache::remember('asset.'.$path, now()->addMinutes(5), function () use ($path) {
            $full = public_path($path);

            return is_file($full) ? (string) filemtime($full) : '';
        });

        return asset($path).($version !== '' ? '?v='.$version : '');
    }
}
