<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The constant factor, onto a database that is already running.
 *
 * SystemSettingSeeder carries it too, but SystemSetting::set() updates an
 * existing row and does nothing for a key that is not there -- so a setting
 * never inserted cannot be changed from the settings screen, and every read
 * falls back to the constant in the code. The row has to exist.
 *
 * 0.0481927 is 1 ÷ 20.75 truncated at seven decimals, which is the figure the
 * CSC and DBM issuances publish. See App\Services\Leave\LeaveMoneyValue for
 * where the 20.75 comes from and why the truncated form is the one to store.
 */
return new class extends Migration
{
    private const KEY = 'leave.money_value_factor';

    public function up(): void
    {
        if (DB::table('system_settings')->where('key', self::KEY)->exists()) {
            return;
        }

        DB::table('system_settings')->insert([
            'group' => 'leave',
            'key' => self::KEY,
            'value' => '0.0481927',
            'type' => 'string',
            'description' => 'Constant factor for the money value of leave credits (1 ÷ 20.75 working days a month)',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', self::KEY)->delete();
    }
};
