<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * One first-time password for every new account.
 *
 * A new account used to be created with a random fourteen-character password,
 * printed once into a flash message. That is stronger on paper and worse in
 * practice: the message went with the next page, and a password nobody can
 * read back is one that gets written on paper, or asked for again. The
 * administrator was left copying a random string out of a notification before
 * it faded.
 *
 * Nobody keeps it. ForcePasswordChange holds the employee on the
 * change-password screen until they set their own, the change screen refuses
 * a password identical to the current one, and the strength rule requires a
 * symbol -- which this word does not have -- so it cannot be set back either.
 *
 * It is not a secret. What stops a name plus this word from being a sign-in is
 * the one-time code emailed to the employee's own address.
 */
class FirstPasswordTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private Position $position;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->department = Department::factory()->create();
        $this->position = Position::factory()->create();
        $this->actingAs($this->makeUser('system-admin'));
        session(['otp_verified' => true]);
    }

    private function first(): string
    {
        return config('security.first_password');
    }

    // ------------------------------------------------------------ the value

    public function test_the_lgu_word_is_what_ships(): void
    {
        $this->assertSame('OneAlicia2026', $this->first());
    }

    // ----------------------------------------------------------- on creating

    public function test_a_new_account_is_created_with_it(): void
    {
        $this->post('/users', $this->payload())->assertRedirect();

        $user = User::where('username', 'jdelacruz')->firstOrFail();

        $this->assertTrue(Hash::check($this->first(), $user->password),
            'the account was not created with the first-time password');
    }

    /** And has to leave it behind at the first sign-in. */
    public function test_the_account_arrives_owing_a_password_change(): void
    {
        $this->post('/users', $this->payload())->assertRedirect();

        $this->assertTrue(User::where('username', 'jdelacruz')->firstOrFail()->must_change_password);
    }

    /** The administrator is told it plainly, because it is not a secret. */
    public function test_the_administrator_is_told_what_to_pass_on(): void
    {
        $this->post('/users', $this->payload())
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $s) => str_contains($s, $this->first()));
    }

    /** And is told before saving, not only after. */
    public function test_the_form_says_it_up_front(): void
    {
        $this->get('/users/create')->assertOk()->assertSee($this->first());
    }

    /** Nothing random is issued any more, so nothing has to be copied down. */
    public function test_no_random_password_is_generated(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Admin/UserController.php'));

        $this->assertStringNotContainsString('Str::password', $source);
    }

    // ------------------------------------------------------- on resetting

    public function test_an_administrator_reset_returns_to_the_same_word(): void
    {
        $user = $this->makeUser('employee');

        $this->from('/users')->post('/users/'.$user->id.'/reset-password')
            ->assertRedirect('/users')
            ->assertSessionHas('status', fn (string $s) => str_contains($s, $this->first()));

        $user->refresh();
        $this->assertTrue(Hash::check($this->first(), $user->password));
        $this->assertTrue($user->must_change_password);
    }

    // ------------------------------------------------- nobody keeps it

    /**
     * The whole arrangement rests on this. If the word could be kept, every
     * account in the LGU would sit on a password anyone could guess.
     */
    public function test_the_employee_cannot_go_anywhere_until_they_change_it(): void
    {
        $this->post('/users', $this->payload())->assertRedirect();
        $employee = User::where('username', 'jdelacruz')->firstOrFail();

        $this->actingAs($employee);
        session(['otp_verified' => true]);

        $this->get('/dashboard')->assertRedirect(route('password.change'));
        $this->get('/leave/apply')->assertRedirect(route('password.change'));
    }

    /** And cannot simply set it again. */
    public function test_it_cannot_be_set_back_as_the_new_password(): void
    {
        $this->post('/users', $this->payload())->assertRedirect();
        $employee = User::where('username', 'jdelacruz')->firstOrFail();

        $this->actingAs($employee);
        session(['otp_verified' => true]);

        $this->from(route('password.change'))->post(route('password.change'), [
            'current_password' => $this->first(),
            'password' => $this->first(),
            'password_confirmation' => $this->first(),
        ])->assertSessionHasErrors('password');

        $this->assertTrue($employee->fresh()->must_change_password,
            'the first-time password was accepted as the employee\'s own');
    }

    /** A password of their own is accepted, and the hold lifts. */
    public function test_their_own_password_is_accepted(): void
    {
        $this->post('/users', $this->payload())->assertRedirect();
        $employee = User::where('username', 'jdelacruz')->firstOrFail();

        $this->actingAs($employee);
        session(['otp_verified' => true]);

        $this->post(route('password.change'), [
            'current_password' => $this->first(),
            'password' => 'Alicia!Isabela24', 'password_confirmation' => 'Alicia!Isabela24',
        ])->assertRedirect(route('dashboard'));

        $employee->refresh();
        $this->assertFalse($employee->must_change_password);
        $this->assertFalse(Hash::check($this->first(), $employee->password));
    }

    // ----------------------------------------------------------- the trail

    /** The audit trail records that it was issued, never the word itself. */
    public function test_the_password_is_not_written_into_the_audit_trail(): void
    {
        $this->post('/users', $this->payload())->assertRedirect();

        $entry = AuditLog::where('action', 'user_created')->firstOrFail();

        $this->assertSame('[STANDARD]', $entry->new_values['temp_password']);
        $this->assertStringNotContainsString($this->first(), json_encode($entry->new_values));
        $this->assertStringContainsString('first-time password', $entry->meaning);
    }

    /** @param array<string,mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Juan Dela Cruz',
            'username' => 'jdelacruz',
            'email' => 'juan@alicia.gov.ph',
            'roles' => [Role::where('slug', 'employee')->firstOrFail()->id],
            'first_name' => 'Juan',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'gender' => 'male',
            'civil_status' => 'single',
            'birth_date' => '1990-04-12',
            'contact_no' => '0917 123 4567',
            'address' => '12 Rizal St, Alicia',
            'department_id' => $this->department->id,
            'position_id' => $this->position->id,
            'employment_status' => 'permanent',
            'salary' => 25000,
            'date_hired' => '2015-06-01',
        ], $overrides);
    }
}
