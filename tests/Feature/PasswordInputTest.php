<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_renders_a_labeled_password_field_with_a_visibility_control(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('id="password"', false)
            ->assertSee('name="password"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertSee('type="password"', false)
            ->assertSee('aria-label="Show password"', false)
            ->assertSee('x-on:click', false);
    }
}
