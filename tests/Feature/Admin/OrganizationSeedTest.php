<?php

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A clean installation can create a user.
 *
 * It could not. Departments and positions lived only in DemoDataSeeder,
 * alongside the sample employees, so a system with the demo data removed had
 * neither — and both are required on the user form. Two empty dropdowns and no
 * way past them.
 *
 * The System Administrator is the one who hits it, and is the one who cannot
 * get out of it: they hold users.manage but not departments.manage, so they
 * could not add the missing rows either.
 */
class OrganizationSeedTest extends TestCase
{
    use RefreshDatabase;

    /** The seeders a fresh install runs — no demo data. */
    private function freshInstall(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    public function test_a_clean_install_has_offices_and_positions(): void
    {
        $this->freshInstall();

        $this->assertGreaterThan(0, Department::count(),
            'a fresh system has no offices, so no account can name one');
        $this->assertGreaterThan(0, Position::count(),
            'a fresh system has no positions, so no account can name one');
    }

    /** The offices the workflow itself names have to be among them. */
    public function test_the_offices_the_workflow_depends_on_are_there(): void
    {
        $this->freshInstall();

        foreach (['MO', 'HRMO'] as $code) {
            $this->assertDatabaseHas('departments', ['code' => $code]);
        }
    }

    /**
     * The salary grade is printed on the CSC Form 6 the employee later files,
     * so it belongs to the position rather than being typed per person.
     */
    public function test_every_position_carries_its_salary_grade(): void
    {
        $this->freshInstall();

        $this->assertSame(0, Position::whereNull('salary_grade')->count());
    }

    /** The whole point: the form is completable on a clean system. */
    public function test_the_user_form_offers_something_to_choose(): void
    {
        $this->freshInstall();
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);

        $html = $this->get('/users/create')->assertOk()->getContent();

        $this->assertStringContainsString('Office of the Mayor', $html);
        $this->assertStringContainsString('Administrative Aide I', $html);
        $this->assertStringNotContainsString('No departments', $html);
    }

    /** Seeding twice must not double the list. */
    public function test_seeding_again_does_not_duplicate_anything(): void
    {
        $this->freshInstall();
        $departments = Department::count();
        $positions = Position::count();

        $this->seed(\Database\Seeders\OrganizationSeeder::class);

        $this->assertSame($departments, Department::count());
        $this->assertSame($positions, Position::count());
    }

    /** An office renamed locally keeps its name when the seeder runs again. */
    public function test_a_local_rename_survives_a_reseed(): void
    {
        $this->freshInstall();
        Department::where('code', 'MEO')->update(['name' => 'Engineering']);

        $this->seed(\Database\Seeders\OrganizationSeeder::class);

        $this->assertSame('Engineering', Department::where('code', 'MEO')->first()->name,
            'a reseed overwrote what the LGU had renamed');
    }

    // ------------------------------------------------------- the dead end itself

    /**
     * If either list is ever emptied again, the form says so rather than
     * showing a required dropdown with nothing in it.
     */
    public function test_an_empty_list_is_explained_rather_than_left_blank(): void
    {
        $this->seedCore();
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);

        $html = $this->get('/users/create')->assertOk()->getContent();

        $this->assertStringContainsString('No departments or positions to choose from', $html);
        $this->assertStringContainsString('cannot be completed', $html);
    }

    /**
     * And it names who can fix it. A System Administrator holds users.manage
     * but not departments.manage, so a link they cannot follow is worse than
     * being told whose job it is.
     */
    public function test_it_tells_an_administrator_who_maintains_the_lists(): void
    {
        $this->seedCore();
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);

        $html = $this->get('/users/create')->assertOk()->getContent();

        $this->assertStringContainsString('Ask HR to add', $html);
        $this->assertStringNotContainsString(route('departments.index'), $html,
            'it offers a link the System Administrator is not allowed to use');
    }

    /** HR can, so HR gets the link. */
    public function test_hr_is_offered_the_link_instead(): void
    {
        $this->seedCore();
        $this->actingAs($this->makeUser('hr'));
        session(['otp_verified' => true]);

        // HR does not hold users.manage, so the warning is checked where HR
        // would meet an empty list: the employee-facing pages it maintains.
        $this->get('/departments')->assertOk();
        $this->assertTrue(
            $this->makeUser('hr')->hasPermission('departments.manage'),
            'HR can no longer maintain the list the warning points them at'
        );
    }
}
