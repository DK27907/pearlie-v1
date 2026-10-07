<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_landing_page_renders_at_root(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('MediDesk AI')
            ->assertSee('How it works')
            ->assertSee('Pricing')
            ->assertSee('Frequently asked questions');
    }

    public function test_landing_page_has_all_sections(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee("Your Hospital's 24/7", false);
        $response->assertSee("The front desk can't keep up", false);
        $response->assertSee('Missed calls');
        $response->assertSee('After-hours gaps');
        $response->assertSee('No-shows');
        $response->assertSee('M-Pesa Deposits');
        $response->assertSee('Product demo video');
        $response->assertSee('Doctor dashboard');
        $response->assertSee('Admin dashboard');
        $response->assertSee('KES 50,000');
        $response->assertSee('KES 75,000');
        $response->assertSee('KES 100,000+');
        $response->assertDontSee('<img', false);
    }

    public function test_landing_page_cta_links_are_present(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('mailto:sales@axiomforge.co.ke', false)
            ->assertSee('https://wa.me/254707799114', false);
    }

    public function test_pearlie_assistant_remains_available_separately(): void
    {
        $this->get('/pearlie')
            ->assertOk()
            ->assertSee('Chat with Pearlie');
    }
}
