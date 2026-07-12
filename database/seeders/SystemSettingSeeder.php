<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // group, key, value, type, description
            ['auth', 'auth.otp_enabled', '1', 'bool', 'Require email OTP as a second factor at login'],
            ['auth', 'auth.otp_ttl_minutes', '5', 'int', 'OTP validity window in minutes'],
            ['auth', 'auth.lockout_attempts', '3', 'int', 'Failed logins before the account is blocked'],
            ['auth', 'auth.lockout_hours', '24', 'int', 'Hours an auto-blocked account stays blocked'],
            ['auth', 'auth.session_idle_minutes', '30', 'int', 'Idle minutes before forced logout'],
            ['security', 'security.device_enforcement', '0', 'bool', 'Only authorized devices may access the system'],
            ['security', 'security.ids_enabled', '1', 'bool', 'Enable intrusion detection middleware'],
            ['security', 'security.auto_block_threshold', '5', 'int', 'Intrusion events from one IP before auto-block'],
            ['security', 'security.auto_block_window_minutes', '10', 'int', 'Sliding window for the auto-block threshold'],
            ['security', 'security.ip_block_hours', '24', 'int', 'Hours an auto-blocked IP stays blocked'],
            ['security', 'security.rate_limit_per_minute', '120', 'int', 'Requests/minute per IP before a rate anomaly is logged'],
            ['leave', 'leave.monthly_vl_accrual', '1.25', 'string', 'Vacation Leave credits earned per month'],
            ['leave', 'leave.monthly_sl_accrual', '1.25', 'string', 'Sick Leave credits earned per month'],
            ['leave', 'leave.vl_hard_deadline_days', '3', 'int', 'HR rule: VL must be filed N days ahead (warning + HR override)'],
            ['general', 'general.lgu_name', 'Local Government Unit of Alicia', 'string', 'Organization name on forms and reports'],
            ['general', 'general.alerts_poll_seconds', '15', 'int', 'Dashboard alert polling interval'],
        ];

        foreach ($settings as [$group, $key, $value, $type, $description]) {
            SystemSetting::updateOrCreate(['key' => $key], compact('group', 'value', 'type', 'description'));
        }
    }
}
