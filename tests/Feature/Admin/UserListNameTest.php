<?php

namespace Tests\Feature\Admin;

use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The user list's three name columns, and when each account was created.
 *
 * It had one column reading "Maria Dela Cruz" and no created date, so it could
 * answer neither "where is Dela Cruz in this list?" nor "has this person been
 * set up yet?". Last name, first name and middle initial now have a column
 * each, in the order a government roster is read.
 *
 * The ordering had to move with them. orderBy('name') sorted by the account's
 * "Maria Dela Cruz" -- by first name -- and a list headed by surnames in no
 * surname order reads as a list in no order at all.
 */
class UserListNameTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seedCore();
        $user = $this->makeUser('system-admin');
        $this->actingAs($user);
        session(['otp_verified' => true]);

        return $user;
    }

    private function employee(string $first, ?string $middle, string $last, string $account): User
    {
        $user = User::factory()->create(['name' => $account]);

        EmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
        ]);

        return $user->fresh();
    }

    /** The middle name becomes one letter and a full stop. */
    public function test_a_middle_name_is_shown_as_an_initial(): void
    {
        $this->seedCore();

        $user = $this->employee('Maria', 'Santos', 'Dela Cruz', 'Maria Dela Cruz');

        $this->assertSame('S.', $user->employeeProfile->middleInitial());
        $this->assertSame('Dela Cruz', $user->surname());
    }

    /**
     * No middle name leaves the cell EMPTY, not a dash.
     *
     * A narrow column of em-dashes beside the people who do have one reads as
     * data somebody forgot to enter, rather than a name that does not exist.
     */
    public function test_no_middle_name_leaves_the_initial_empty(): void
    {
        $this->seedCore();

        $user = $this->employee('Jose', null, 'Rizal', 'Jose Rizal');

        $this->assertSame('', $user->employeeProfile->middleInitial(),
            'a person with no middle name is given an initial anyway');
    }

    /** A middle name of nothing but spaces is not a middle name either. */
    public function test_a_blank_middle_name_leaves_the_initial_empty(): void
    {
        $this->seedCore();

        $user = $this->employee('Jose', '   ', 'Rizal', 'Jose Rizal');

        $this->assertSame('', $user->employeeProfile->middleInitial());
    }

    /**
     * An account with no employee profile still shows a person.
     *
     * The IT administrator has no leave entitlement and therefore no profile.
     * Without the fallback that row would render an empty name.
     */
    public function test_an_account_without_an_employee_profile_falls_back_to_its_own_name(): void
    {
        $this->seedCore();

        $user = User::factory()->create(['name' => 'System Administrator']);

        $this->assertSame('System Administrator', $user->surname());
    }

    /** And the page draws all three columns, with the creation date. */
    public function test_the_list_shows_the_three_name_columns_and_the_created_date(): void
    {
        $this->admin();

        $user = $this->employee('Maria', 'Santos', 'Dela Cruz', 'Maria Dela Cruz');
        $user->forceFill(['created_at' => Carbon::parse('2026-03-14 09:00:00')])->save();

        $html = $this->get(route('users.index'))->assertOk()
            ->assertSee('Last name')
            ->assertSee('First name')
            ->assertSee('M.I.')
            ->assertSee('14 Mar 2026')
            ->getContent();

        // Three separate cells, not one joined string. The surname carries
        // the edit link, so it is an anchor rather than a bare span.
        $this->assertStringContainsString('class="person-name name-link">Dela Cruz</a>', $html);
        $this->assertStringContainsString('<td>Maria</td>', $html);
        $this->assertStringContainsString('<td>S.</td>', $html);
        $this->assertStringNotContainsString('Dela Cruz, Maria', $html,
            'the columns were joined back into one string');
    }

    /** The M.I. cell is genuinely empty when there is no middle name. */
    public function test_the_middle_initial_cell_is_empty_on_the_page(): void
    {
        $this->admin();

        $this->employee('Jose', null, 'Rizal', 'Jose Rizal');

        $html = $this->get(route('users.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<td>Jose</td>', $html);
        $this->assertStringContainsString('<td></td>', $html,
            'the middle initial cell carries a placeholder instead of being empty');
    }

    /**
     * Sorted by surname, not by the account name.
     *
     * Ordered by users.name these come back Ana, Maria, Zenaida. Ordered by
     * surname: Aquino, Bautista, Dela Cruz. This assertion fails under the old
     * ordering, which is the point of it.
     */
    public function test_the_list_is_ordered_by_surname(): void
    {
        $this->admin();

        $this->employee('Ana', null, 'Bautista', 'Ana Bautista');
        $this->employee('Maria', null, 'Dela Cruz', 'Maria Dela Cruz');
        $this->employee('Zenaida', null, 'Aquino', 'Zenaida Aquino');

        $html = $this->get(route('users.index'))->assertOk()->getContent();

        $at = fn (string $surname) => strpos($html, 'class="person-name name-link">'.$surname.'</a>');

        foreach (['Aquino', 'Bautista', 'Dela Cruz'] as $surname) {
            $this->assertNotFalse($at($surname), "{$surname} is missing from the list");
        }

        $this->assertTrue($at('Aquino') < $at('Bautista'));
        $this->assertTrue($at('Bautista') < $at('Dela Cruz'),
            'the list is not in surname order');
    }

    /**
     * The avatar disc is still keyed off the whole account name.
     *
     * Its colour is a hash of whatever string it is given, so keying it off a
     * single column would make the same person one colour here and another on
     * every other page -- the bug the shared hash exists to prevent. "Maria
     * Dela Cruz" gives MC; "Dela Cruz" alone would give D.
     */
    public function test_the_avatar_is_unchanged_by_the_split_columns(): void
    {
        $this->admin();

        $this->employee('Maria', 'Santos', 'Dela Cruz', 'Maria Dela Cruz');

        $this->get(route('users.index'))
            ->assertOk()
            ->assertSee('>MC<', false);
    }
}
