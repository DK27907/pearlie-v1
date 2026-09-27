<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use App\Models\Hospital;
use App\Services\KnowledgeBaseService;
use Database\Seeders\KnowledgeBaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeBaseServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_swahili_greetings_and_services_return_swahili_answers(): void
    {
        $this->seed(KnowledgeBaseSeeder::class);
        $knowledgeBase = app(KnowledgeBaseService::class);

        $this->assertStringContainsString('Habari!', $knowledgeBase->search('HABARI'));
        $this->assertStringContainsString('Hospitali ya Pearl Hospital', $knowledgeBase->search('HUDUMA ZENU'));
    }

    public function test_keyword_matching_ignores_case_and_diacritics(): void
    {
        KnowledgeBase::query()->create([
            'category' => 'test',
            'subcategory' => 'diacritics',
            'keywords' => ['café'],
            'question' => 'Where can I get café?',
            'answer' => 'Matched normalized keyword.',
            'source' => 'Feature test',
            'last_updated' => now(),
        ]);

        $this->assertSame(
            'Matched normalized keyword.',
            app(KnowledgeBaseService::class)->search('CAFÉ'),
        );
    }

    public function test_contact_answers_use_the_current_hospital_details(): void
    {
        $hospital = Hospital::query()->where('slug', 'pearl')->firstOrFail();
        $hospital->update([
            'name' => 'Example Hospital',
            'address' => 'Main Street, Nyahururu',
            'email' => 'hello@example.test',
            'phone' => '0700111222',
            'emergency_phone' => '0700333444',
            'website' => 'https://example.test',
        ]);
        app()->instance('currentHospital', $hospital->fresh());
        $this->seed(KnowledgeBaseSeeder::class);

        $answer = app(KnowledgeBaseService::class)->search('contact phone email');

        $this->assertStringContainsString('0700111222', $answer);
        $this->assertStringContainsString('hello@example.test', $answer);
        $this->assertStringContainsString('Main Street, Nyahururu', $answer);
    }

    public function test_seeding_updates_existing_entries_without_creating_duplicates(): void
    {
        $this->seed(KnowledgeBaseSeeder::class);
        $countAfterFirstSeed = KnowledgeBase::query()->count();

        app(KnowledgeBaseSeeder::class)->run();

        $this->assertSame($countAfterFirstSeed, KnowledgeBase::query()->count());
        $this->assertSame(
            1,
            KnowledgeBase::query()
                ->where('category', 'services')
                ->where('subcategory', 'general')
                ->count(),
        );
    }
}
