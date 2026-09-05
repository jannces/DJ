<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\Position;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\AuditNarrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The audit trail has to be readable by the person who reads it.
 *
 * The Changes column printed the log exactly as it is stored: pretty-printed
 * JSON, the entire row before the save and the differences after it. The
 * administrator opening this page is an HR officer, and "must_change_password":
 * 0 with "blocked_until": null is not something they should have to decode --
 * nor should they have to find the one field that moved among twenty that did
 * not.
 *
 * Nothing about what is recorded changes here. What is stored is still the
 * whole before-image, which is what makes the trail worth having; this is only
 * about how it reads.
 */
class AuditReadabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private array $wholeRow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $this->admin = $this->makeUser('system-admin');
        $this->admin->update(['name' => 'Noly J. Macarubbo', 'email' => 'noly@alicia.gov.ph']);

        $this->wholeRow = $this->admin->fresh()->getAttributes();
        $this->wholeRow['password'] = '[REDACTED]';
        $this->wholeRow['remember_token'] = '[REDACTED]';

        $this->actingAs($this->admin);
        session(['otp_verified' => true]);
    }

    /** @param array<string,mixed>|null $new */
    private function entry(string $action, ?array $new, ?array $old = null, ?string $type = null, $id = null): AuditLog
    {
        return AuditLog::create([
            'user_id' => $this->admin->id,
            'role_snapshot' => 'system-admin',
            'action' => $action,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'old_values' => $old,
            'new_values' => $new,
            'ip' => '127.0.0.1',
        ]);
    }

    private function page(): string
    {
        return $this->get('/audit-logs')->assertOk()->getContent();
    }

    // ------------------------------------------------- no data structures

    public function test_the_page_prints_no_json(): void
    {
        $this->entry('user_updated', ['name' => 'Noly J. Macarubbo'], $this->wholeRow, User::class, $this->admin->id);

        $html = $this->page();

        $this->assertStringNotContainsString('"old":', $html);
        $this->assertStringNotContainsString('"new":', $html);
        $this->assertStringNotContainsString('JSON_PRETTY_PRINT', $html);
        $this->assertStringNotContainsString('<pre', $html, 'the changes are still a code block');
    }

    /**
     * The whole point. A save writes the entire row into old_values and only
     * the differences into new_values, so the differences are the list.
     */
    public function test_only_the_field_that_changed_is_shown(): void
    {
        $entry = $this->entry('user_updated',
            ['name' => 'Noly Jose Macarubbo', 'updated_at' => '2026-08-30 07:22:58'],
            $this->wholeRow, User::class, $this->admin->id);

        $changes = $entry->change_list;

        $this->assertCount(1, $changes, 'unchanged fields, or the timestamp, are being listed as changes');
        $this->assertSame('Name', $changes[0]['label']);
        $this->assertSame('Noly J. Macarubbo', $changes[0]['from']);
        $this->assertSame('Noly Jose Macarubbo', $changes[0]['to']);
    }

    public function test_column_names_are_written_as_labels(): void
    {
        $this->entry('user_updated', [
            'must_change_password' => 1, 'failed_attempts' => 5,
            'blocked_reason' => 'Unusual activity', 'last_login_ip' => '192.168.1.9',
        ], $this->wholeRow, User::class, $this->admin->id);

        $html = $this->page();

        $this->assertStringContainsString('Must set a new password at next sign-in', $html);
        $this->assertStringContainsString('Failed sign-in attempts', $html);
        $this->assertStringContainsString('Reason for blocking', $html);
        $this->assertStringContainsString('Last signed in from', $html);
        $this->assertStringNotContainsString('must_change_password', $html);
        $this->assertStringNotContainsString('failed_attempts', $html);
    }

    // ------------------------------------------------------------- values

    public function test_stored_ones_and_zeroes_are_read_as_yes_and_no(): void
    {
        $entry = $this->entry('user_updated', ['must_change_password' => 1],
            $this->wholeRow, User::class, $this->admin->id);

        $this->assertSame('No', $entry->change_list[0]['from']);
        $this->assertSame('Yes', $entry->change_list[0]['to']);
    }

    public function test_an_empty_value_says_so_in_words(): void
    {
        $entry = $this->entry('user_updated', ['blocked_reason' => null],
            ['blocked_reason' => 'Unusual activity'] + $this->wholeRow, User::class, $this->admin->id);

        $this->assertSame('not set', $entry->change_list[0]['to'],
            'null is being shown to somebody who does not know what null is');
    }

    public function test_timestamps_are_read_as_dates(): void
    {
        $entry = $this->entry('user_updated', ['blocked_until' => '2026-09-01 08:00:00'],
            $this->wholeRow, User::class, $this->admin->id);

        $this->assertSame('Sep 01, 2026 8:00 AM', $entry->change_list[0]['to']);
    }

    /** A date with no time of day stays a date rather than gaining midnight. */
    public function test_a_plain_date_does_not_invent_a_time(): void
    {
        $entry = $this->entry('created', ['date_hired' => '2015-06-01']);

        $this->assertSame('Jun 01, 2015', $entry->change_list[0]['to']);
    }

    /** An id is a name to everyone except the database. */
    public function test_an_id_is_resolved_to_the_thing_it_points_at(): void
    {
        AuditNarrator::forget();
        $office = Department::create(['name' => 'Municipal Engineering Office', 'code' => 'MEO']);

        $entry = $this->entry('created', ['department_id' => $office->id]);

        $this->assertSame('Office', $entry->change_list[0]['label']);
        $this->assertSame('Municipal Engineering Office', $entry->change_list[0]['to']);
    }

    /** Archiving exists so the record stays readable; the name must survive. */
    public function test_an_archived_employees_name_still_resolves(): void
    {
        AuditNarrator::forget();
        $leaver = $this->makeUser('employee');
        $leaver->update(['name' => 'Maria Santos']);
        $entry = $this->entry('leave_approved', ['user_id' => $leaver->id]);

        $leaver->delete();
        AuditNarrator::forget();

        $this->assertSame('Maria Santos', $entry->fresh()->change_list[0]['to']);
    }

    // --------------------------------------------------------- the secrets

    /**
     * Both sides of a password read "[REDACTED]", because neither is stored.
     * Dropping the pair as unchanged would hide a password change altogether
     * -- the one change a security log most needs to carry. The key is only
     * written when it actually moved, so its presence is the fact.
     */
    public function test_a_password_change_is_reported_without_being_shown(): void
    {
        $entry = $this->entry('user_updated', ['password' => '[REDACTED]'],
            $this->wholeRow, User::class, $this->admin->id);

        $changes = $entry->change_list;

        $this->assertCount(1, $changes, 'a password change went unreported');
        $this->assertSame('Password', $changes[0]['label']);
        $this->assertStringContainsString('never shown', $changes[0]['to']);
        $this->assertStringNotContainsString('REDACTED', $this->page());
    }

    public function test_a_generated_password_is_described_not_printed(): void
    {
        $entry = $this->entry('user_created', ['email' => 'juan@alicia.gov.ph', 'temp_password' => '[GENERATED]']);

        $this->assertSame('generated by the system', $entry->change_list[1]['to']);
    }

    // ---------------------------------------------------- the other columns

    public function test_the_action_is_a_phrase_not_a_slug(): void
    {
        $this->entry('ip_blocked_from_evidence', ['ip' => '192.168.1.55']);
        $this->entry('user_archived', null);

        $html = $this->page();

        $this->assertStringContainsString('IP blocked from the intrusion list', $html);
        $this->assertStringContainsString('User archived', $html);
        // The slug survives in one place only -- the filter's option value,
        // which is what the query matches on. Nothing reads it.
        $this->assertSame(1, substr_count($html, 'ip_blocked_from_evidence'));
    }

    /** Including in the filter beside it, which still submits the slug. */
    public function test_the_action_filter_reads_the_same_as_the_column(): void
    {
        $this->entry('ip_unblocked', ['active' => false], ['active' => true]);

        $html = $this->page();

        $this->assertStringContainsString('value="ip_unblocked"', $html,
            'the filter no longer submits something the query can match');
        $this->assertMatchesRegularExpression('#value="ip_unblocked"[^>]*>IP block lifted<#', $html);
    }

    public function test_the_target_names_the_record_rather_than_numbering_it(): void
    {
        $this->entry('user_updated', ['name' => 'x'], $this->wholeRow, User::class, $this->admin->id);

        $html = $this->page();

        $this->assertStringContainsString('User account — Noly J. Macarubbo', $html);
        $this->assertStringNotContainsString('App\Models\User', $html);
    }

    public function test_the_role_is_written_as_it_is_everywhere_else(): void
    {
        $this->entry('user_updated', ['name' => 'x'], $this->wholeRow, User::class, $this->admin->id);

        $this->assertStringContainsString('System admin', $this->page());
    }

    // ----------------------------------------------------------- the shape

    /**
     * One entry that touched a dozen fields must not push the rest of the
     * page out of reach.
     */
    public function test_a_long_list_of_changes_folds_away(): void
    {
        $office = Department::create(['name' => 'Municipal Engineering Office', 'code' => 'MEO']);
        $position = Position::factory()->create(['title' => 'Engineer II']);
        $profile = EmployeeProfile::factory()->create([
            'user_id' => $this->admin->id, 'employee_no' => 'EMP-0001',
            'department_id' => $office->id, 'position_id' => $position->id,
        ]);
        $this->entry('created', $profile->getAttributes(), null, EmployeeProfile::class, $profile->id);

        $html = $this->page();

        $this->assertStringContainsString('class="audit-more"', $html);
        $this->assertMatchesRegularExpression('#<summary>\d+ more</summary>#', $html);
        // Opened or closed, the markup is one well-formed block.
        $this->assertSame(substr_count($html, '<details class="audit-more">'),
            substr_count($html, '</details>'), 'the fold is not closed');
    }

    /**
     * An entry with nothing to list and nothing to say shows a dash, not an
     * empty box -- and not an explanation nobody wrote.
     */
    public function test_an_entry_with_nothing_to_show_shows_a_dash(): void
    {
        $entry = $this->entry('some_unnarrated_action', null, null, User::class, $this->admin->id);

        $this->assertSame([], $entry->change_list);
        $this->assertNull($entry->meaning);
        $this->assertStringContainsString('—', $this->page());
    }

    /** Settings are logged as key => [old, new] already; that shape holds. */
    public function test_a_settings_change_reads_from_and_to(): void
    {
        $entry = $this->entry('settings_updated', [
            'security.rate_limit_per_minute' => ['old' => '120', 'new' => '200'],
            'security.otp_required' => ['old' => '0', 'new' => '1'],
        ]);

        $changes = $entry->change_list;

        $this->assertSame(['Rate limit per minute', '120', '200'],
            [$changes[0]['label'], $changes[0]['from'], $changes[0]['to']]);
        $this->assertSame(['OTP required', 'No', 'Yes'],
            [$changes[1]['label'], $changes[1]['from'], $changes[1]['to']]);
    }

    /** A nested payload is counted, not spelled out into the column. */
    public function test_a_nested_payload_is_summarised(): void
    {
        $entry = $this->entry('ip_auto_blocked', [
            'ip' => '192.168.1.55',
            'events' => [['type' => 'sqli'], ['type' => 'sqli'], ['type' => 'xss']],
        ]);

        $this->assertSame('Attempts recorded', $entry->change_list[1]['label']);
        $this->assertSame('3 entries', $entry->change_list[1]['to']);
    }

    // ------------------------------------------------- what it actually did

    /**
     * A field and its new value say what was written; they do not say what it
     * did, and that is the question somebody opens the audit trail with.
     */
    public function test_each_entry_says_what_it_meant(): void
    {
        $entry = $this->entry('account_blocked', ['reason' => 'Exceeded 3 failed login attempts'],
            null, User::class, $this->admin->id);

        $this->assertStringContainsString('after too many wrong passwords', $entry->meaning);
        $this->assertStringContainsString('after too many wrong passwords', $this->page());
    }

    /** The one an administrator has to be able to tell apart from a block. */
    public function test_deactivating_is_described_as_the_thing_it_is_for(): void
    {
        $away = $this->entry('user_status_toggled', ['status' => 'inactive'], null, User::class, $this->admin->id);
        $back = $this->entry('user_status_toggled', ['status' => 'active'], null, User::class, $this->admin->id);

        $this->assertStringContainsString('while somebody is away', $away->meaning);
        $this->assertStringContainsString('can sign in again', $back->meaning);
    }

    /**
     * An entry with no field changes carried nothing at all before. Archiving
     * an account is the most consequential thing on the page and it printed a
     * dash.
     */
    public function test_an_entry_with_no_fields_still_explains_itself(): void
    {
        $entry = $this->entry('user_archived', null, null, User::class, $this->admin->id);

        $this->assertSame([], $entry->change_list);
        $this->assertStringContainsString('nothing about it is deleted', $entry->meaning);
        $this->assertStringContainsString('nothing about it is deleted', $this->page());
    }

    /** Per field, where the field has a consequence worth stating. */
    public function test_a_field_carries_what_it_does(): void
    {
        $entry = $this->entry('user_updated', [
            'status' => 'blocked', 'must_change_password' => 1, 'name' => 'Noly Jose Macarubbo',
        ], $this->wholeRow, User::class, $this->admin->id);

        $notes = collect($entry->change_list)->keyBy('label');

        $this->assertStringContainsString('cannot sign in', $notes['Status']['note']);
        $this->assertStringContainsString('change-password screen',
            $notes['Must set a new password at next sign-in']['note']);
        $this->assertNull($notes['Name']['note'], 'a name is being explained to somebody who can read it');
    }

    /** The threshold is a setting, so the sentence reads it rather than guessing. */
    public function test_the_lockout_threshold_quoted_is_the_one_in_force(): void
    {
        SystemSetting::updateOrCreate(['key' => 'auth.lockout_attempts'],
            ['value' => '5', 'type' => 'int', 'group' => 'auth']);
        Cache::flush();

        $entry = $this->entry('user_updated', ['failed_attempts' => 4],
            $this->wholeRow, User::class, $this->admin->id);

        $this->assertStringContainsString('At 5 the account blocks itself', $entry->change_list[0]['note']);
    }

    /**
     * Descriptions are of behaviour that is in the system, not general
     * advice, so an action nobody has written a sentence for gets none
     * rather than an invented one.
     */
    public function test_an_unknown_action_is_not_given_an_invented_explanation(): void
    {
        $this->assertNull($this->entry('something_new_entirely', ['a' => 'b'])->meaning);
    }

    // ------------------------------------------- the record is not touched

    /** The trail still stores everything it stored before. */
    public function test_nothing_is_removed_from_what_is_recorded(): void
    {
        $entry = $this->entry('user_updated', ['name' => 'Noly Jose Macarubbo'],
            $this->wholeRow, User::class, $this->admin->id);

        $this->assertSame($this->wholeRow, $entry->fresh()->old_values,
            'the before-image is no longer being kept');
    }
}
