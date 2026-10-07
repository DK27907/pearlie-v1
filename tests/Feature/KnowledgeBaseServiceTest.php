<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\KnowledgeBase;
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
        $this->assertStringContainsString($this->hospitalName().' inatoa', $knowledgeBase->search('HUDUMA ZENU'));
        $this->assertStringContainsString('Pearlie', $knowledgeBase->search('hello'));
        $this->assertStringNotContainsString('I’m '.$this->hospitalName(), $knowledgeBase->search('hello'));
    }

    public function test_greeting_uses_the_assistant_name_configured_by_the_hospital(): void
    {
        Hospital::query()->where('slug', 'pearl')->firstOrFail()->update([
            'chatbot_name' => 'Custom Bot',
        ]);
        $this->seed(KnowledgeBaseSeeder::class);

        $answer = app(KnowledgeBaseService::class)->search('Hello');

        $this->assertNotNull($answer);
        $this->assertStringContainsString('I’m Custom Bot', $answer);
        $this->assertStringNotContainsString('I’m Pearl Hospital', $answer);
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

    public function test_service_specific_queries_prioritize_the_matching_service_entry(): void
    {
        $this->seed(KnowledgeBaseSeeder::class);

        $answer = app(KnowledgeBaseService::class)->search('Tell me about dialysis');

        $this->assertNotNull($answer);
        $this->assertStringContainsString('hemodialysis', strtolower($answer));
        $this->assertStringNotContainsString('outpatient', strtolower($answer));
    }

    public function test_dental_service_queries_return_only_dental_information(): void
    {
        $this->seed(KnowledgeBaseSeeder::class);

        foreach ([
            'Do you offer dental services?',
            'Dental services you offer',
            'Tell me about dental',
        ] as $query) {
            $answer = app(KnowledgeBaseService::class)->search($query);

            $this->assertNotNull($answer);
            $this->assertStringContainsString('Dental Unit', $answer, $query);
            $this->assertStringNotContainsString('dialysis', strtolower($answer));
            $this->assertStringNotContainsString('oncology', strtolower($answer));
        }
    }

    public function test_greeting_does_not_hide_a_specific_service_question(): void
    {
        $this->seed(KnowledgeBaseSeeder::class);

        $answer = app(KnowledgeBaseService::class)->search('Hello, do you offer dental services?');

        $this->assertNotNull($answer);
        $this->assertStringContainsString('Dental Unit', $answer);
        $this->assertStringNotContainsString('Hello!', $answer);
    }

    public function test_oncology_queries_return_only_the_matching_service_entry(): void
    {
        $this->seed(KnowledgeBaseSeeder::class);

        $answer = app(KnowledgeBaseService::class)->search('What is oncology?');

        $this->assertNotNull($answer);
        $this->assertStringContainsString('cancer care', strtolower($answer));
        $this->assertStringNotContainsString('dialysis', strtolower($answer));
    }

    public function test_service_overview_queries_return_the_overview_entry(): void
    {
        $this->seed(KnowledgeBaseSeeder::class);

        $answer = app(KnowledgeBaseService::class)->search('What services do you offer?');

        $this->assertNotNull($answer);
        $this->assertStringContainsString('outpatient', strtolower($answer));
    }

    public function test_emergency_intents_return_the_current_hospital_emergency_number(): void
    {
        $this->seed(KnowledgeBaseSeeder::class);
        $emergencyPhone = (string) Hospital::query()->where('slug', 'pearl')->value('emergency_phone');

        $answer = app(KnowledgeBaseService::class)->search('I need emergency help');

        $this->assertNotNull($answer);
        $this->assertStringContainsString($emergencyPhone, $answer);
    }

    public function test_swahili_emergency_symptoms_return_the_localized_emergency_response(): void
    {
        $this->seed(KnowledgeBaseSeeder::class);
        $emergencyPhone = (string) Hospital::query()->where('slug', 'pearl')->value('emergency_phone');

        $answer = app(KnowledgeBaseService::class)->search('Nina maumivu ya kifua');

        $this->assertSame(
            'Kwa dharura, piga '.$emergencyPhone.' sasa. Ninakuunganisha na mhudumu wa afya.',
            $answer,
        );
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
                ->where('subcategory', 'services_overview')
                ->count(),
        );
    }

    private function hospitalName(): string
    {
        return (string) Hospital::query()->where('slug', 'pearl')->value('name');
    }
}
