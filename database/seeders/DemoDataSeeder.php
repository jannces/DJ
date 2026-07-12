<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * OPTIONAL demo dataset for evaluation/defense. Never run on live data.
 * Creates one account per role plus sample employees with credited balances.
 * All demo passwords: "Alicia@2026Demo!" (must_change_password is set).
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $departments = collect([
            ['name' => "Mayor's Office", 'code' => 'MO'],
            ['name' => 'Human Resource Management Office', 'code' => 'HRMO'],
            ['name' => 'Municipal Treasury Office', 'code' => 'MTO'],
            ['name' => 'Municipal Engineering Office', 'code' => 'MEO'],
            ['name' => 'Municipal Health Office', 'code' => 'MHO'],
        ])->map(fn ($d) => Department::updateOrCreate(['code' => $d['code']], $d));

        $positions = collect([
            ['title' => 'Administrative Aide', 'salary_grade' => 'SG-4'],
            ['title' => 'Administrative Officer II', 'salary_grade' => 'SG-11'],
            ['title' => 'HR Management Officer', 'salary_grade' => 'SG-15'],
            ['title' => 'Municipal Engineer', 'salary_grade' => 'SG-24'],
            ['title' => 'Nurse II', 'salary_grade' => 'SG-16'],
            ['title' => 'Municipal Mayor', 'salary_grade' => 'SG-27'],
        ])->map(fn ($p) => Position::updateOrCreate(['title' => $p['title']], $p));

        $password = Hash::make('Alicia@2026Demo!');
        $make = function (array $user, string $roleSlug, array $profile) use ($password): User {
            $account = User::updateOrCreate(['email' => $user['email']], $user + [
                'password' => $password,
                'status' => User::STATUS_ACTIVE,
                'must_change_password' => true,
                'email_verified_at' => now(),
            ]);
            $account->roles()->syncWithoutDetaching(Role::where('slug', $roleSlug)->first());
            EmployeeProfile::updateOrCreate(['user_id' => $account->id], $profile + [
                'employee_no' => $profile['employee_no'],
            ]);

            return $account;
        };

        $hrmo = $departments->firstWhere('code', 'HRMO');
        $mo = $departments->firstWhere('code', 'MO');
        $meo = $departments->firstWhere('code', 'MEO');

        $sysadmin = $make(
            ['name' => 'Sam Icta', 'username' => 'sysadmin', 'email' => 'sysadmin@alicia.gov.ph'],
            'system-admin',
            ['employee_no' => 'EMP-0001', 'first_name' => 'Sam', 'last_name' => 'Icta',
             'salary' => 35000, 'department_id' => $mo->id,
             'position_id' => $positions->firstWhere('title', 'Administrative Officer II')->id,
             'date_hired' => '2020-01-15', 'gender' => 'male', 'civil_status' => 'single',
             'address' => 'Poblacion, Alicia'],
        );

        $hr = $make(
            ['name' => 'Helen Reyes', 'username' => 'hrofficer', 'email' => 'hr@alicia.gov.ph'],
            'hr',
            ['employee_no' => 'EMP-0002', 'first_name' => 'Helen', 'last_name' => 'Reyes',
             'salary' => 42000, 'department_id' => $hrmo->id,
             'position_id' => $positions->firstWhere('title', 'HR Management Officer')->id,
             'date_hired' => '2018-06-01', 'gender' => 'female', 'civil_status' => 'married',
             'address' => 'Barangay Calaocan, Alicia'],
        );

        $head = $make(
            ['name' => 'Diego Santos', 'username' => 'depthead', 'email' => 'depthead@alicia.gov.ph'],
            'department-head',
            ['employee_no' => 'EMP-0003', 'first_name' => 'Diego', 'last_name' => 'Santos',
             'salary' => 65000, 'department_id' => $meo->id,
             'position_id' => $positions->firstWhere('title', 'Municipal Engineer')->id,
             'date_hired' => '2015-03-10', 'gender' => 'male', 'civil_status' => 'married',
             'address' => 'Barangay Magsaysay, Alicia'],
        );
        $meo->update(['head_user_id' => $head->id]);

        $mayor = $make(
            ['name' => 'Maria Alicia Cruz', 'username' => 'mayor', 'email' => 'mayor@alicia.gov.ph'],
            'mayor',
            ['employee_no' => 'EMP-0004', 'first_name' => 'Maria Alicia', 'last_name' => 'Cruz',
             'salary' => 105000, 'department_id' => $mo->id,
             'position_id' => $positions->firstWhere('title', 'Municipal Mayor')->id,
             'date_hired' => '2022-07-01', 'gender' => 'female', 'civil_status' => 'married',
             'address' => 'Poblacion, Alicia'],
        );
        $mo->update(['head_user_id' => $mayor->id]);

        $employee = $make(
            ['name' => 'Juan Dela Cruz', 'username' => 'employee', 'email' => 'employee@alicia.gov.ph'],
            'employee',
            ['employee_no' => 'EMP-0005', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
             'salary' => 18000, 'department_id' => $meo->id,
             'position_id' => $positions->firstWhere('title', 'Administrative Aide')->id,
             'date_hired' => '2023-02-20', 'gender' => 'male', 'civil_status' => 'single',
             'address' => 'Barangay Victoria, Alicia', 'is_solo_parent' => false],
        );

        // Additional rank-and-file employees for report volume.
        User::factory()->count(8)->create()->each(function (User $extra) use ($departments, $positions) {
            $extra->roles()->syncWithoutDetaching(Role::where('slug', 'employee')->first());
            EmployeeProfile::factory()->create([
                'user_id' => $extra->id,
                'department_id' => $departments->random()->id,
                'position_id' => $positions->random()->id,
            ]);
        });

        // Credit opening balances (15 VL / 15 SL) to everyone with a profile.
        $vl = LeaveType::where('code', 'VL')->first();
        $sl = LeaveType::where('code', 'SL')->first();
        foreach (User::whereHas('employeeProfile')->get() as $user) {
            foreach ([$vl, $sl] as $type) {
                LeaveBalance::updateOrCreate(
                    ['user_id' => $user->id, 'leave_type_id' => $type->id],
                    ['earned' => 15, 'used' => 0, 'balance' => 15],
                );
            }
        }
    }
}
