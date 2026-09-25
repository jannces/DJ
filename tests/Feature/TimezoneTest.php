<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The system runs on Philippine time.
 *
 * It was running on UTC, and the gap showed up the way these things always
 * do -- somebody deactivated a device at 11:51 in the morning and the list
 * said 3:51. Eight hours early on a leave form is a nuisance; eight hours
 * early in an audit trail is a security record that disagrees with every
 * other clock in the building.
 *
 * The part worth a test is not the value. It is that .env ALREADY said
 * APP_TIMEZONE=Asia/Manila and config/app.php had 'UTC' written into it
 * literally, so the setting existed, looked configured, and did nothing. A
 * test on the value alone would have passed just as happily with the
 * hardcoded string, so there are two here.
 */
class TimezoneTest extends TestCase
{
    /** Philippine Standard Time: a fixed UTC+8, no daylight saving since 1978. */
    public function test_the_application_runs_on_philippine_time(): void
    {
        $this->assertSame('Asia/Manila', config('app.timezone'));
        $this->assertSame('Asia/Manila', date_default_timezone_get(),
            'the config says Manila but PHP was never told');

        $this->assertSame(8 * 3600, Carbon::now()->getOffset(),
            'the clock is not eight hours ahead of UTC');
    }

    /**
     * And the value comes from the environment, not from the file.
     *
     * This is the assertion that would have caught the original fault. A
     * deployment sets APP_TIMEZONE in .env and is entitled to expect it to be
     * used; a literal in config/app.php silently overrules it.
     */
    public function test_the_timezone_is_read_from_the_environment(): void
    {
        $source = file_get_contents(config_path('app.php'));

        $this->assertMatchesRegularExpression(
            "/'timezone'\s*=>\s*env\(\s*'APP_TIMEZONE'/", $source,
            'config/app.php hardcodes the timezone again, so APP_TIMEZONE in .env is ignored');

        // The fallback matters as much as the variable: .env is not in the
        // repository, so a fresh install has only this to go on.
        $this->assertMatchesRegularExpression(
            "/env\(\s*'APP_TIMEZONE'\s*,\s*'Asia\/Manila'\s*\)/", $source,
            'a deployment without APP_TIMEZONE set would fall back to something other than Manila');
    }

    /**
     * The nightly backup runs at 1am local, which is what 01:00 was meant to be.
     *
     * Under UTC that line fired at 9am Manila -- during office hours, on a
     * database people were using. The schedule string did not change; the
     * timezone it is read in did.
     */
    public function test_the_nightly_backup_is_scheduled_in_local_time(): void
    {
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);

        $backup = collect($schedule->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'lms:backup'));

        $this->assertNotNull($backup, 'the nightly backup is no longer scheduled');
        $this->assertSame('0 1 * * *', $backup->expression);
        $this->assertSame('Asia/Manila', $backup->timezone ?? config('app.timezone'));
    }
}
