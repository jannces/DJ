<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Whether the database can be reached, and what to do when it cannot.
 *
 * Extracted from the backup command because update.bat needs the same answer
 * before it migrates, and a second copy of this reasoning would drift from the
 * first. The wording is the deliverable here, not the check: the check is two
 * lines, and every version of this failure so far has ended with somebody
 * reading a stack trace that did not contain the words "MySQL is not running".
 */
class DatabaseReachability
{
    /**
     * One sentence naming the cause and the fix, or null if the database is up.
     *
     * One sentence on purpose. BackupController shows the command's last output
     * line on the Backups page, and update.bat prints it in a terminal beside
     * other steps; a diagnosis spread over several lines arrives in both places
     * as its least useful fragment.
     */
    public static function problem(): ?string
    {
        try {
            DB::connection()->getPdo();

            return null;
        } catch (\Throwable $e) {
            $config = DB::connection()->getConfig();
            $where = ($config['host'] ?? '?').':'.($config['port'] ?? '?');

            // 2002 is "nothing answered on that socket". On a XAMPP box that is
            // almost always MySQL simply not started, so say that rather than
            // repeating the driver's wording back at them.
            if (str_contains($e->getMessage(), '[2002]')) {
                return "Cannot reach the database at {$where} - MySQL is not running. "
                    .'Start MySQL in the XAMPP Control Panel and try again. '
                    .'(If MySQL IS running, check that DB_PORT in .env matches the port it uses; '
                    .'XAMPP moves to 3307 when 3306 is taken.)';
            }

            // 1045 is the other common one: it answered and refused us.
            if (str_contains($e->getMessage(), '[1045]')) {
                return "The database at {$where} refused the username or password in .env "
                    .'(DB_USERNAME / DB_PASSWORD).';
            }

            return "Cannot reach the database at {$where}: ".$e->getMessage();
        }
    }
}
