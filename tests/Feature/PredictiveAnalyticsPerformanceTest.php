<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Services\PredictiveAnalyticsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PredictiveAnalyticsPerformanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Only the analytics tables are needed; never use the application's database.
        config([
            'database.default' => 'predictive_testing',
            'database.connections.predictive_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        Carbon::setTestNow('2026-09-07 10:00:00');

        foreach ([
            '2024_01_02_000001_create_events_table.php',
            '2024_01_02_000002_create_registrations_table.php',
            '2024_01_02_000003_create_attendances_table.php',
            '2024_01_02_000004_create_notifications_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_queries_do_not_grow_with_upcoming_event_count(): void
    {
        $this->createHistory();
        $this->createEvent();

        $singleEventQueries = $this->dashboardQueryCount();

        for ($i = 0; $i < 49; $i++) {
            $this->createEvent();
        }

        $manyEventQueries = $this->dashboardQueryCount();

        $this->assertLessThanOrEqual(12, $manyEventQueries);
        $this->assertSame($singleEventQueries, $manyEventQueries);
    }

    public function test_forecast_and_historical_rates_are_preserved(): void
    {
        $this->createHistory();
        $event = $this->createEvent();
        foreach (['confirmed', 'pending', 'cancelled'] as $index => $status) {
            Registration::create(['event_id' => $event->id, 'user_id' => $index + 1, 'status' => $status]);
        }

        $service = new PredictiveAnalyticsService();
        $summary = $service->getHistoricalDataSummary();
        $this->assertSame(1, $summary['total_completed_events']);
        $this->assertSame(2, $summary['total_registrations']);
        $this->assertSame(1, $summary['total_verified_attendances']);
        $this->assertEquals(50, $summary['overall_attendance_rate']);

        $expectedRates = ['events' => 1, 'avg_registrations' => 2.0, 'avg_attendances' => 1.0, 'attendance_rate' => 50.0];
        $this->assertEquals($expectedRates, $service->getCategoryAttendanceRates()['Academic']);
        $this->assertEquals($expectedRates, $service->getVenueAttendanceRates()['Hall']);
        $this->assertEquals($expectedRates, $service->getDayOfWeekPatterns()['Monday']);

        $prediction = $service->predictAllUpcoming()[0];
        $this->assertSame(2, $prediction['current_registrations']);
        $this->assertSame(45, $prediction['predicted_count']);
        $this->assertEquals(45.2, $prediction['predicted_rate']);
        $this->assertSame('high', $prediction['confidence']);
        $standalonePrediction = $service->predictAttendance($event);
        $this->assertSame($standalonePrediction, array_intersect_key($prediction, $standalonePrediction));
    }

    public function test_admin_dashboard_handles_empty_data(): void
    {
        $this->actingAs($this->admin())->get(route('admin.predictive'))
            ->assertOk()
            ->assertSee('No upcoming published events found for prediction.');
    }

    public function test_admin_dashboard_refreshes_history_on_the_next_request(): void
    {
        $this->createHistory();
        $this->createEvent();

        $this->actingAs($this->admin())->get(route('admin.predictive'))
            ->assertOk()
            ->assertViewHas('dataSummary', fn ($summary) => $summary['total_completed_events'] === 1)
            ->assertSee('Analytics test event');

        $this->createEvent(['event_date' => now()->subDays(2)->toDateString()]);

        $this->get(route('admin.predictive'))
            ->assertOk()
            ->assertViewHas('dataSummary', fn ($summary) => $summary['total_completed_events'] === 2);
    }

    public function test_admin_can_export_the_predictive_report(): void
    {
        $this->createHistory();
        $this->createEvent();

        $this->actingAs($this->admin())->get(route('admin.predictive.export-pdf'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    private function dashboardQueryCount(): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $service = new PredictiveAnalyticsService();
        $service->getHistoricalDataSummary();
        $service->predictAllUpcoming();
        $service->getCategoryAttendanceRates();
        $service->getVenueAttendanceRates();
        $service->getDayOfWeekPatterns();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function createHistory(): void
    {
        $event = $this->createEvent(['event_date' => now()->subWeek()->toDateString()]);
        foreach (['verified', 'pending'] as $index => $status) {
            $registration = Registration::create(['event_id' => $event->id, 'user_id' => $index + 1, 'status' => 'confirmed']);
            Attendance::create(['registration_id' => $registration->id, 'status' => $status]);
        }
    }

    private function createEvent(array $overrides = []): Event
    {
        return Event::create(array_replace([
            'title' => 'Analytics test event',
            'venue' => 'Hall',
            'category' => 'Academic',
            'event_date' => now()->addWeek()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '10:00',
            'capacity' => 100,
            'organizer_id' => 1,
            'status' => 'published',
        ], $overrides));
    }

    private function admin(): User
    {
        return (new User())->forceFill([
            'id' => 1,
            'first_name' => 'Test',
            'surname' => 'Admin',
            'email' => 'admin@example.test',
            'role' => 'admin',
            'is_active' => true,
        ]);
    }
}
