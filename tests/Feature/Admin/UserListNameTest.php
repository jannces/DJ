<?php

namespace Tests\Feature\Admin;

use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * How the user list writes a name, and when the account was created.
 *
 * The list wrote "Maria Dela Cruz" and sorted by it -- which is to say, by
 * first name. A government roster is read down its surnames, so the column now
 * writes "Dela Cruz, Maria S." and the query orders by the same thing. Writing
 * one and ordering by the other would have been worse than leaving it alone: a
 * list headed by surnames in no surname order looks broken.
 *
 * The Created column answers two questions the page could not: has this person
 * been set up yet, and who was added this month.
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

    /** Surname, given name, middle initial. */
    public function test_a_name_is_written_surname_first_with_a_middle_initial(): void
    {
        $this->seedCore();

        $user = $this->employee('Maria', 'Santos', 'Dela Cruz', 'Maria Dela Cruz');

        $this->assertSame('Dela Cruz, Maria S.', $user->listName());
    }

    /** Plenty of people have no middle name, and a bare "." is not a name. */
    public function test_a_missing_middle_name_leaves_no_stray_initial(): void
    {
        $this->seedCore();

        $user = $this->employee('Jose', null, 'Rizal', 'Jose Rizal');

        $this->assertSame('Rizal, Jose', $user->listName());
    }

    /**
     * An account with no employee profile still shows a person.
     *
     * The IT administrator has no leave entitlement and therefore no profile.
     * Before the fallback that row would have rendered an empty cell.
     */
    public function test_an_account_without_an_employee_profile_falls_back_to_its_own_name(): void
    {
        $this->seedCore();

        $user = User::factory()->create(['name' => 'System Administrator']);

        $this->assertSame('System Administrator', $user->listName());
    }

    /** And the page renders it that way, with the creation date beside it. */
    public function test_the_list_shows_the_formal_name_and_the_created_date(): void
    {
        $this->admin();

        $user = $this->employee('Maria', 'Santos', 'Dela Cruz', 'Maria Dela Cruz');
        $user->forceFill(['created_at' => Carbon::parse('2026-03-14 09:00:00')])->save();

        $this->get(route('users.index'))
            ->assertOk()
            ->assertSee('Dela Cruz, Maria S.')
            ->assertSee('14 Mar 2026');
    }

    /**
     * Sorted by surname, not by the account name.
     *
     * Ordered by users.name these three come back Ana, Maria, Zenaida. Ordered
     * by surname they come back Bautista, Dela Cruz, Aquino -> Aquino,
     * Bautista, Dela Cruz -- which is the assertion, and it fails under the
     * old ordering.
     */
    public function test_the_list_is_ordered_by_surname(): void
    {
        $this->admin();

        $this->employee('Ana', null, 'Bautista', 'Ana Bautista');
        $this->employee('Maria', null, 'Dela Cruz', 'Maria Dela Cruz');
        $this->employee('Zenaida', null, 'Aquino', 'Zenaida Aquino');

        $html = $this->get(route('users.index'))->assertOk()->getContent();

        $positions = [
            'Aquino, Zenaida' => strpos($html, 'Aquino, Zenaida'),
            'Bautista, Ana' => strpos($html, 'Bautista, Ana'),
            'Dela Cruz, Maria' => strpos($html, 'Dela Cruz, Maria'),
        ];

        foreach ($positions as $name => $at) {
            $this->assertNotFalse($at, "{$name} is missing from the list");
        }

        $this->assertTrue($positions['Aquino, Zenaida'] < $positions['Bautista, Ana']);
        $this->assertTrue($positions['Bautista, Ana'] < $positions['Dela Cruz, Maria'],
            'the list is not in surname order');
    }

    /**
     * The avatar disc is still keyed off the plain name.
     *
     * Its colour is a hash of whatever string it is given, so keying it off
     * the displayed text would make the same person one colour here and
     * another on every other page -- the exact bug the shared hash exists to
     * prevent. "Maria Dela Cruz" gives MC; "Dela Cruz, Maria S." would give DS.
     */
    public function test_the_avatar_is_unchanged_by_the_new_name_order(): void
    {
        $this->admin();

        $this->employee('Maria', 'Santos', 'Dela Cruz', 'Maria Dela Cruz');

        $this->get(route('users.index'))
            ->assertOk()
            ->assertSee('Dela Cruz, Maria S.')
            ->assertSee('>MC<', false);
    }
}
