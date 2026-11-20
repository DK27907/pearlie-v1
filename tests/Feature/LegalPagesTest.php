<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    public function test_privacy_page_is_public_and_returns_200(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertSee('Privacy Policy')
            ->assertSeeText('Last updated: November 20, 2026')
            ->assertDontSee('<script', false)
            ->assertSee('AxiomForge Digital Solutions');
    }

    public function test_terms_page_is_public_and_returns_200(): void
    {
        $this->get('/terms')
            ->assertOk()
            ->assertSee('Terms of Service')
            ->assertSee('The AI assistant is not a doctor');
    }

    public function test_data_deletion_page_is_public_and_returns_200(): void
    {
        $this->get('/data-deletion')
            ->assertOk()
            ->assertSee('Data Deletion Instructions')
            ->assertSee('support@medidesk.co.ke')
            ->assertSee('within 72 hours');
    }

    public function test_legal_pages_do_not_require_authentication_or_tenant_context(): void
    {
        $this->assertGuest();
        app()->forgetInstance('currentHospital');

        foreach (['/privacy', '/terms', '/data-deletion'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertDontSee('<form', false);
            $this->assertNull(hospital());
            $this->assertGuest();
        }
    }
}
