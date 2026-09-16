<?php

namespace Tests\Feature\Leave;

use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\LeaveType;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A field the form insists on must not be labelled as if it were optional.
 *
 * Found in Week 8 system testing: "Specify location" is required for Vacation
 * Leave — the CSC form has a blank after "Within the Philippines" as well as
 * after "Abroad" — but the form labelled it "If abroad, specify". Everyone
 * taking leave inside the country did as the label said, left it empty, and
 * was refused by a message naming a field that was not on the screen. The most
 * ordinary application in the system could not be filed.
 */
class RequiredFieldLabelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    private function openForm(): string
    {
        $user = $this->makeUser('employee');
        $department = Department::create(['name' => 'Municipal Engineering Office', 'code' => 'MEO']);
        $position = Position::create(['title' => 'Administrative Aide IV', 'salary_grade' => 4]);
        EmployeeProfile::create([
            'user_id' => $user->id,
            'employee_no' => 'EMP-8001',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'department_id' => $department->id,
            'position_id' => $position->id,
            'salary' => 18000,
            'date_hired' => now()->subYears(2),
        ]);

        $this->actingAs($user);
        session(['otp_verified' => true]);

        return $this->get(route('leave.create'))->assertOk()->getContent();
    }

    public function test_the_vacation_location_field_is_not_labelled_as_abroad_only(): void
    {
        $html = $this->openForm();

        $this->assertMatchesRegularExpression(
            '/<label[^>]*for="location_specify"[^>]*>\s*Specify location/i',
            $html,
            'The location field should be labelled the way the CSC form and the validator call it.'
        );
        $this->assertStringNotContainsString('If abroad, specify', $html);
    }

    public function test_every_required_detail_field_on_the_form_is_marked_required(): void
    {
        $html = $this->openForm();

        foreach (LeaveType::all() as $type) {
            foreach ($type->detail_schema ?? [] as $field) {
                if (! ($field['required'] ?? false)) {
                    continue;
                }

                $name = $field['name'];

                // Only the control that actually carries details[<name>]; the
                // form also has unrelated top-level inputs whose id happens to
                // match a detail field's name.
                if (! str_contains($html, 'name="details['.$name.']"')) {
                    continue;
                }
                if (! preg_match(
                    '/<label[^>]*for="'.preg_quote($name, '/').'"[^>]*>(.*?)<\/label>\s*<input[^>]*name="details\['.preg_quote($name, '/').'\]"/is',
                    $html, $m)) {
                    continue; // labelled by a fieldset or a segmented choice instead
                }

                $label = trim(strip_tags($m[1]));

                $this->assertFalse(str_starts_with($label, 'If '),
                    "{$type->name}: '{$name}' is required, but the form labels it \"{$label}\", "
                    .'which reads as something to fill in only sometimes.');

                // Some fields sit in a block shared by several leave types and
                // are required for only one of them; there a standing asterisk
                // would mislead the others, so a hint that names the type is
                // the honest marker. Either way the filer must be able to tell.
                $position = strpos($html, $m[0]);
                $around = substr($html, $position, 700);

                $this->assertTrue(
                    str_contains($m[0], 'class="req"') || str_contains($around, 'Required for'),
                    "{$type->name}: '{$name}' is required, but nothing on the form says so."
                );
            }
        }
    }
}
