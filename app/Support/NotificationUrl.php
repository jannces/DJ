<?php

namespace App\Support;

/**
 * Turn a stored notification link into a path the browser resolves itself.
 *
 * Notifications keep their target in the `data` JSON, written once when the
 * notification is created. `route()` returns an ABSOLUTE url, so what got
 * stored was the address the system happened to answer on that day:
 *
 *     http://192.168.254.103:8000/leave/24
 *
 * Every one of those froze. The server moved to another address, then to
 * HTTPS on 443 under a hostname, and every notification created before the
 * move still pointed at a machine and a port that no longer answer --
 * ERR_CONNECTION_REFUSED, from a link inside a working system.
 *
 * A path has no such problem. "/leave/24" is resolved by the browser against
 * whatever host it is already on, so the same row works on 127.0.0.1, on
 * https://onealicialms.lan, and on whatever the LGU's network calls it later.
 *
 * Notifications now store paths. This exists for the rows that do not: it
 * reduces anything absolute back to its path at render time, so old rows heal
 * without a data migration and a future absolute url cannot break links again.
 */
class NotificationUrl
{
    /**
     * @param  string|null  $stored  the url as found in the notification payload
     * @param  string  $fallback  where to send someone when it is unusable
     */
    public static function path(?string $stored, string $fallback): string
    {
        $stored = trim((string) $stored);

        if ($stored === '') {
            return $fallback;
        }

        // A protocol-relative "//host/path" LOOKS relative and is not: the
        // browser would leave for that host. It has to go through parse_url
        // with everything else, so check it before the plain "/" case.
        if (! str_starts_with($stored, '//') && str_starts_with($stored, '/')) {
            return $stored;
        }

        $parts = parse_url($stored);

        if ($parts === false || ! isset($parts['path']) || ! str_starts_with($parts['path'], '/')) {
            return $fallback;
        }

        // Deliberately keeping only the path, even when the host is not ours.
        // Nothing in this system writes an external link into a notification,
        // and if something ever does, landing on our own page is a better
        // outcome than following it.
        $path = $parts['path'];

        if (isset($parts['query'])) {
            $path .= '?'.$parts['query'];
        }

        if (isset($parts['fragment'])) {
            $path .= '#'.$parts['fragment'];
        }

        return $path;
    }
}
