<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The three CSC thresholds, onto a database that is already running.
 *
 * SystemSettingSeeder carries them too, but SystemSetting::set() updates an
 * existing row and does nothing at all for a key that is not there -- so a
 * setting that has never been inserted cannot be changed from the settings
 * screen, and every read silently falls back to the default in the code. The
 * rows have to exist.
 */
return new class extends Migration
{
    /** @var list<array{0:string,1:string,2:string}> key, value, description */
    private const SETTINGS = [
        ['leave.monetization_min_days', '10', 'Fewest leave credits that may be monetized at once'],
        ['leave.monetization_retain_days', '15', 'Vacation Leave days that must remain after monetizing'],
        ['leave.forced_leave_min_vl', '10', 'Vacation Leave credits from which the 5-day mandatory leave applies'],
    ];

    public function up(): void
    {
        foreach (self::SETTINGS as [$key, $value, $description]) {
            if (DB::table('system_settings')->where('key', $key)->exists()) {
                continue;
            }

            DB::table('system_settings')->insert([
                'group' => 'leave',
                'key' => $key,
                'value' => $value,
                'type' => 'string',
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('system_settings')
            ->whereIn('key', array_column(self::SETTINGS, 0))
            ->delete();
    }
};
