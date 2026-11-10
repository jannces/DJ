<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Position;
use Illuminate\Database\Seeder;

/**
 * The LGU's offices and plantilla positions.
 *
 * These lived only in DemoDataSeeder, alongside the sample employees — so a
 * clean installation had none of them. Department and position are both
 * required on the user form, which meant a fresh system could not create a
 * single account: two empty dropdowns and no way past them. Worse for the
 * System Administrator, who holds users.manage but not departments.manage, so
 * they could not add the missing rows either.
 *
 * They are not demo data. An office and a plantilla item are the municipality's
 * own structure, and the system cannot describe an employee without them.
 *
 * This is a starting point, not a fixture: HR maintains both lists from
 * Departments and Positions, and the seeder only makes sure a row exists --
 * it never writes over one that is already there.
 */
class OrganizationSeeder extends Seeder
{
    /**
     * The offices a municipality runs under the Local Government Code, plus
     * the ones Alicia's own workflow names — the Mayor decides applications
     * and HR certifies them, so both need an office to belong to.
     */
    private const DEPARTMENTS = [
        ['code' => 'MO', 'name' => "Office of the Mayor"],
        ['code' => 'OVM', 'name' => 'Office of the Vice Mayor'],
        ['code' => 'SB', 'name' => 'Sangguniang Bayan Office'],
        ['code' => 'HRMO', 'name' => 'Human Resource Management Office'],
        ['code' => 'MTO', 'name' => "Municipal Treasurer's Office"],
        ['code' => 'MASSO', 'name' => "Municipal Assessor's Office"],
        ['code' => 'MACCO', 'name' => 'Municipal Accounting Office'],
        ['code' => 'MBO', 'name' => 'Municipal Budget Office'],
        ['code' => 'MPDO', 'name' => 'Municipal Planning and Development Office'],
        ['code' => 'MEO', 'name' => 'Municipal Engineering Office'],
        ['code' => 'MHO', 'name' => 'Municipal Health Office'],
        ['code' => 'MSWDO', 'name' => 'Municipal Social Welfare and Development Office'],
        ['code' => 'MAGRO', 'name' => 'Municipal Agriculture Office'],
        ['code' => 'OMCR', 'name' => 'Office of the Municipal Civil Registrar'],
        ['code' => 'MDRRMO', 'name' => 'Municipal Disaster Risk Reduction and Management Office'],
        ['code' => 'GSO', 'name' => 'General Services Office'],
    ];

    /**
     * Plantilla items and their salary grades.
     *
     * The grade is printed on the CSC Form 6 the employee later files, so it
     * belongs to the position rather than being typed per person.
     */
    private const POSITIONS = [
        ['title' => 'Administrative Aide I', 'salary_grade' => 'SG 1'],
        ['title' => 'Administrative Aide III', 'salary_grade' => 'SG 3'],
        ['title' => 'Administrative Aide IV', 'salary_grade' => 'SG 4'],
        ['title' => 'Administrative Aide VI', 'salary_grade' => 'SG 6'],
        ['title' => 'Administrative Assistant II', 'salary_grade' => 'SG 8'],
        ['title' => 'Administrative Officer I', 'salary_grade' => 'SG 10'],
        ['title' => 'Administrative Officer II', 'salary_grade' => 'SG 11'],
        ['title' => 'Administrative Officer IV', 'salary_grade' => 'SG 15'],
        ['title' => 'Administrative Officer V', 'salary_grade' => 'SG 18'],
        ['title' => 'Human Resource Management Officer II', 'salary_grade' => 'SG 15'],
        ['title' => 'Accountant I', 'salary_grade' => 'SG 12'],
        ['title' => 'Budget Officer II', 'salary_grade' => 'SG 15'],
        ['title' => 'Engineer II', 'salary_grade' => 'SG 16'],
        ['title' => 'Nurse II', 'salary_grade' => 'SG 16'],
        ['title' => 'Midwife I', 'salary_grade' => 'SG 11'],
        ['title' => 'Social Welfare Officer I', 'salary_grade' => 'SG 11'],
        ['title' => 'Agriculturist II', 'salary_grade' => 'SG 15'],
        ['title' => 'Registration Officer II', 'salary_grade' => 'SG 15'],
        ['title' => 'Information Systems Analyst I', 'salary_grade' => 'SG 12'],
        ['title' => 'Computer Programmer II', 'salary_grade' => 'SG 15'],
        ['title' => 'Municipal Government Department Head I', 'salary_grade' => 'SG 24'],
        ['title' => 'Municipal Vice Mayor', 'salary_grade' => 'SG 25'],
        ['title' => 'Municipal Mayor', 'salary_grade' => 'SG 27'],
    ];

    public function run(): void
    {
        // firstOrCreate, not updateOrCreate: this makes sure a row exists and
        // then leaves it alone. An office the LGU has renamed, or a salary
        // grade it has corrected, must survive the seeder running again --
        // after a re-seed, or an upgrade. updateOrCreate would put the
        // starting values back over the top of the real ones.
        foreach (self::DEPARTMENTS as $department) {
            Department::firstOrCreate(['code' => $department['code']], $department);
        }

        // Positions are keyed on the title, since there is no code to key on.
        // A position renamed locally therefore reappears under its original
        // title on the next run; HR archives the one it does not want. The
        // alternative -- keying on nothing -- duplicates the whole list.
        foreach (self::POSITIONS as $position) {
            Position::firstOrCreate(['title' => $position['title']], $position);
        }
    }
}
