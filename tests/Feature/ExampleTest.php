<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        // The root route is behind auth; unauthenticated users land on /login.
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk();
    }
}
