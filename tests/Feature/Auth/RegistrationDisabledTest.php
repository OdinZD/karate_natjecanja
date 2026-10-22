<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RegistrationDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_routes_do_not_exist(): void
    {
        $this->assertFalse(Route::has('register'));
        $this->assertFalse(Route::has('register.store'));
    }

    public function test_login_page_renders_without_a_sign_up_link(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Sign up');
    }
}
