<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The address line under the LGU name on every printed form.
 *
 * `leave/form6.blade.php` has read general.lgu_address since the header was
 * redrawn, and `SystemSettingSeeder` never inserted it — the same trap
 * 2026_09_06_000003 was written for. The consequence is narrower but worse to
 * discover: the office prints CSC Form 6 with an address it cannot change,
 * because the settings screen only lists rows that exist and
 * SystemSetting::set() updates a row that is not there into nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('system_settings')->where('key', 'general.lgu_address')->exists()) {
            return;
        }

        DB::table('system_settings')->insert([
            'group' => 'general',
            'key' => 'general.lgu_address',
            'value' => 'Magsaysay, Alicia',
            'type' => 'string',
            'description' => 'Address line printed under the LGU name on forms and reports',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'general.lgu_address')->delete();
    }
};
