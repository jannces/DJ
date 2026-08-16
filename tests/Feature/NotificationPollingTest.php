<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPollingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    private function notify(User $user, array $data): void
    {
        $user->notifications()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'App\\Notifications\\LeaveStatusNotification',
            'data' => $data,
            'read_at' => null,
        ]);
    }

    public function test_polling_endpoint_requires_authentication(): void
    {
        $this->getJson(route('web.notifications.unread'))->assertStatus(401);
    }

    public function test_it_reports_zero_when_there_is_nothing_unread(): void
    {
        $this->actingAs($this->makeUser());

        $this->getJson(route('web.notifications.unread'))
            ->assertOk()
            ->assertJson(['unread' => 0, 'latest' => null]);
    }

    public function test_it_reports_the_unread_count_and_newest_item(): void
    {
        $user = $this->makeUser();
        $this->notify($user, ['title' => 'Leave LR-0001: Submitted', 'message' => 'Older', 'url' => '/leave/1']);
        $this->notify($user, ['title' => 'Leave LR-0001: Approved', 'message' => 'Newest', 'url' => '/leave/1']);

        $this->actingAs($user);

        $response = $this->getJson(route('web.notifications.unread'))->assertOk();
        $response->assertJsonPath('unread', 2);
        $response->assertJsonPath('latest.title', 'Leave LR-0001: Approved');
        $response->assertJsonPath('latest.url', '/leave/1');
    }

    public function test_a_user_never_sees_another_users_notifications(): void
    {
        $employee = $this->makeUser();
        $other = $this->makeUser();
        $this->notify($other, ['title' => 'Not yours', 'message' => '', 'url' => null]);

        $this->actingAs($employee);

        $this->getJson(route('web.notifications.unread'))
            ->assertOk()
            ->assertJson(['unread' => 0, 'latest' => null]);
    }

    public function test_read_notifications_are_excluded(): void
    {
        $user = $this->makeUser();
        $this->notify($user, ['title' => 'Leave LR-0002: Approved', 'message' => '', 'url' => null]);
        $user->unreadNotifications->markAsRead();

        $this->actingAs($user);

        $this->getJson(route('web.notifications.unread'))
            ->assertOk()
            ->assertJson(['unread' => 0]);
    }

    public function test_missing_payload_keys_do_not_break_the_endpoint(): void
    {
        $user = $this->makeUser();
        $this->notify($user, ['message' => 'no title key']);

        $this->actingAs($user);

        $this->getJson(route('web.notifications.unread'))
            ->assertOk()
            ->assertJsonPath('latest.title', 'Notification')
            ->assertJsonPath('latest.url', null);
    }
}
