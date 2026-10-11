<?php

namespace Tests\Feature;

use App\Mail\MarketingDemoRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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
        $response->assertSee('media.gettyimages.com', false);
        $response->assertSee('alt="Virtual assistant helping a person through an online chat"', false);
        $response->assertSee('Always here for patients');
        $response->assertSee('How can I help you today?');
        $response->assertSee('Image: Getty Images');
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
    }

    public function test_landing_page_cta_links_are_present(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('href="#demo"', false)
            ->assertSee(route('marketing.demo.store'), false)
            ->assertDontSee('0707799114', false);
    }

    public function test_demo_request_sends_email_and_redirects_with_confirmation(): void
    {
        Mail::fake();

        $response = $this->post(route('marketing.demo.store'), [
            'name' => 'Amina Wanjiku',
            'email' => 'amina@example.test',
            'organization' => 'Example Hospital',
            'phone' => '0712345678',
            'message' => 'Please show us the appointment workflow.',
        ]);

        $response->assertRedirect(route('home').'#demo')
            ->assertSessionHas('demo_request_sent');
        Mail::assertSent(MarketingDemoRequest::class, fn (MarketingDemoRequest $mail): bool => $mail->hasTo('sales@axiomforge.co.ke')
            && $mail->request['email'] === 'amina@example.test'
            && $mail->request['organization'] === 'Example Hospital');
    }

    public function test_demo_request_requires_contact_and_hospital_details(): void
    {
        Mail::fake();

        $this->from(route('home').'#demo')
            ->post(route('marketing.demo.store'), [])
            ->assertRedirect(route('home').'#demo')
            ->assertSessionHasErrors(['name', 'email', 'organization']);

        Mail::assertNothingOutgoing();
    }

    public function test_only_the_marketing_homepage_allows_the_licensed_getty_image(): void
    {
        $response = $this->get('/')
            ->assertOk()
            ->assertHeaderContains('Content-Security-Policy', "img-src 'self' data: https://media.gettyimages.com;");

        $assistantResponse = $this->get('/pearlie')->assertOk();
        $this->assertStringNotContainsString(
            'https://media.gettyimages.com',
            (string) $assistantResponse->headers->get('Content-Security-Policy'),
        );
    }

    public function test_content_security_policy_nonce_allows_authorized_inline_scripts(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeaderContains('Content-Security-Policy', "script-src 'self' 'nonce-");
    }

    public function test_pearlie_assistant_remains_available_separately(): void
    {
        $this->get('/pearlie')
            ->assertOk()
            ->assertSee('Pearlie AI Assistant')
            ->assertSee('Back to homepage');
    }
}
