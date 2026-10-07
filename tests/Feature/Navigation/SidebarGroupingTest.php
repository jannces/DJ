<?php

namespace Tests\Feature\Navigation;

use Tests\TestCase;

/**
 * Which section of the rail each entry sits in.
 *
 * The sidebar is a flat list in config/menu.php where a heading claims every
 * item after it until the next heading. That makes placement invisible in the
 * markup and easy to undo by accident: moving an entry one line changes which
 * section it belongs to, with nothing to notice.
 *
 * These read the config rather than the rendered page on purpose. The rendered
 * rail only shows what one role can see, so a placement mistake would be
 * visible in one test user's page and not another's.
 */
class SidebarGroupingTest extends TestCase
{
    /** @return array<string, list<string>> heading => the labels under it */
    private function sections(): array
    {
        $sections = [];
        $current = '';

        foreach (config('menu') as $item) {
            if (isset($item['heading'])) {
                $current = $item['heading'];
                $sections[$current] ??= [];

                continue;
            }

            $sections[$current][] = $item['label'];
        }

        return $sections;
    }

    /**
     * Somebody else's leave lives under HR Management.
     *
     * The Leave section is a person's own: apply, their requests, their
     * signature, their audit trail. Approving other people's applications and
     * reading all of them are HR functions and now sit with the rest of them.
     */
    public function test_the_approval_pages_sit_under_hr_management(): void
    {
        $sections = $this->sections();

        $this->assertContains('Leave Approvals', $sections['HR Management']);
        $this->assertContains('All Leave Requests', $sections['HR Management']);

        $this->assertNotContains('Leave Approvals', $sections['Leave'],
            'Leave Approvals is back in the personal Leave section');
        $this->assertNotContains('All Leave Requests', $sections['Leave']);
    }

    /** And the Leave section is only the signed-in person's own. */
    public function test_the_leave_section_is_personal(): void
    {
        $this->assertSame(
            ['Apply for Leave', 'My Leave Requests', 'My Signature', 'My Audit Log'],
            $this->sections()['Leave'],
        );
    }

    /** Backups are an administrator's tool and sit with the rest of them. */
    public function test_backups_sit_under_administration(): void
    {
        $this->assertContains('Backups', $this->sections()['Administration']);
    }

    /**
     * The two audit entries are different pages behind different permissions.
     *
     * "My Audit Log" is the holder's own rows and everybody has it;
     * "Audit Logs" is the whole trail and stays with the administrator. If
     * these ever collapsed onto one route or one permission, the split would
     * be cosmetic.
     */
    public function test_the_two_audit_entries_stay_separate(): void
    {
        $items = collect(config('menu'))->whereNull('heading')->keyBy('label');

        $this->assertSame('audit.mine', $items['My Audit Log']['route']);
        $this->assertSame('audit.view-own', $items['My Audit Log']['permission']);

        $this->assertSame('audit.index', $items['Audit Logs']['route']);
        $this->assertSame('audit.view', $items['Audit Logs']['permission']);
    }

    /**
     * No heading is left with nothing under it.
     *
     * The sidebar hides a heading whose items are all invisible, so this is
     * about the config itself: a heading followed immediately by another
     * heading is a section somebody meant to fill.
     */
    public function test_no_section_is_empty(): void
    {
        foreach ($this->sections() as $heading => $labels) {
            if ($heading === '') {
                continue;
            }

            $this->assertNotEmpty($labels, "the \"{$heading}\" section has no items");
        }
    }
}
