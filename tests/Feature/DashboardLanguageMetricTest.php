<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Hospital;
use App\Models\User;
use App\Services\AdminDashboardService;
use App\Services\NoShowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DashboardLanguageMetricTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_shows_current_month_language_counts_and_percentage(): void
    {
        $this->travelTo('2026-11-19 12:00:00');
        $hospital = Hospital::factory()->create();
        app()->instance('currentHospital', $hospital);
        $admin = User::factory()->for($hospital, 'hospital')->create([
            'role' => 'hospital_admin',
            'is_admin' => true,
        ]);
        $this->createConversations($hospital, ['en', 'en', 'sw', 'sw', 'sw']);
        $this->createConversations($hospital, ['sw'], createdAt: now()->subMonth());

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Language Distribution (This Month)')
            ->assertSee('Consider adding a Swahili-speaking receptionist.')
            ->assertViewHas('language_breakdown', [
                'english' => 2,
                'swahili' => 3,
                'swahili_percent' => 60,
            ]);

        $this->assertSame(
            ['english' => 2, 'swahili' => 3, 'swahili_percent' => 60],
            app(AdminDashboardService::class)->getLanguageBreakdown($hospital),
        );
    }

    public function test_super_admin_impersonation_uses_the_impersonated_hospital_for_language_fallback(): void
    {
        $this->travelTo('2026-11-19 12:00:00');
        $hospital = Hospital::factory()->create(['name' => 'Impersonated Hospital']);
        $platformHospital = Hospital::factory()->create(['name' => 'Platform Hospital']);
        $superAdmin = User::factory()->for($platformHospital, 'hospital')->create([
            'role' => 'super_admin',
            'is_super_admin' => true,
        ]);
        app()->instance('currentHospital', $hospital);
        $this->createConversations($hospital, ['en', 'sw']);
        app()->instance('currentHospital', $platformHospital);
        $this->createConversations($platformHospital, ['en', 'en', 'en']);
        app()->forgetInstance('currentHospital');

        $realDashboardService = app(AdminDashboardService::class);
        $dashboardService = Mockery::mock(AdminDashboardService::class)->makePartial();
        $dashboardService->shouldReceive('dashboardData')
            ->once()
            ->andReturnUsing(function (NoShowService $noShowService) use ($realDashboardService): array {
                $data = $realDashboardService->dashboardData($noShowService);
                unset($data['language_breakdown']);

                return $data;
            });
        $this->app->instance(AdminDashboardService::class, $dashboardService);

        $this->actingAs($superAdmin)
            ->withSession(['impersonating_hospital_id' => $hospital->id])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('1 English | 1 Swahili (50%)')
            ->assertViewHas('language_breakdown', [
                'english' => 1,
                'swahili' => 1,
                'swahili_percent' => 50,
            ]);
    }

    public function test_super_admin_dashboard_aggregates_languages_across_hospitals(): void
    {
        $this->travelTo('2026-11-19 12:00:00');
        app()->forgetInstance('currentHospital');
        $hospital = Hospital::factory()->create(['name' => 'English Hospital']);
        $secondHospital = Hospital::factory()->create(['name' => 'Swahili Hospital']);
        $platformHospital = Hospital::factory()->create();
        $superAdmin = User::factory()->for($platformHospital, 'hospital')->create([
            'role' => 'super_admin',
            'is_super_admin' => true,
        ]);
        app()->instance('currentHospital', $hospital);
        $this->createConversations($hospital, ['en', 'sw']);
        app()->instance('currentHospital', $secondHospital);
        $this->createConversations($secondHospital, ['sw', 'sw']);
        app()->forgetInstance('currentHospital');

        $this->actingAs($superAdmin)
            ->get(route('superadmin.dashboard'))
            ->assertOk()
            ->assertSee('1 English | 3 Swahili (75%)')
            ->assertSee('English Hospital')
            ->assertSee('Swahili Hospital')
            ->assertViewHas('hospitalLanguageBreakdown', static fn (array $rows): bool => collect($rows)->contains(static fn (array $row): bool => $row === ['name' => 'English Hospital', 'english' => 1, 'swahili' => 1])
                && collect($rows)->contains(static fn (array $row): bool => $row === ['name' => 'Swahili Hospital', 'english' => 0, 'swahili' => 2])
            );
    }

    /**
     * @param  list<string>  $languages
     */
    private function createConversations(Hospital $hospital, array $languages, ?\DateTimeInterface $createdAt = null): void
    {
        foreach ($languages as $index => $language) {
            $conversation = Conversation::query()->create([
                'hospital_id' => $hospital->id,
                'session_id' => $hospital->slug.'-language-'.$language.'-'.$index.'-'.str()->uuid(),
                'user_message' => $language === 'sw' ? 'Habari, nataka miadi.' : 'Hello, I need an appointment.',
                'ai_response' => 'Thanks for contacting us.',
                'confidence_score' => 1,
                'channel' => 'web',
                'language' => $language,
            ]);
            if ($createdAt !== null) {
                $conversation->forceFill([
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ])->saveQuietly();
            }
        }
    }
}
