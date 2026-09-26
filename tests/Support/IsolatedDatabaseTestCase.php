<?php

namespace Tests\Support;

use App\Models\Event;
use App\Models\User;
use Carbon\Carbon;
use Tests\TestCase;

abstract class IsolatedDatabaseTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // These tests must never migrate, seed, or clear the configured application database.
        config([
            'database.default' => 'review_testing',
            'database.connections.review_testing' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        Carbon::setTestNow('2026-09-15 10:00:00');
        foreach ([
            '0001_01_01_000000_create_courses_sections_table.php',
            '0001_01_01_000000_create_users_table.php',
            '2024_01_02_000001_create_events_table.php',
            '2024_01_02_000002_create_registrations_table.php',
            '2024_01_02_000003_create_attendances_table.php',
            '2024_01_02_000004_create_notifications_table.php',
            '2024_01_03_000001_create_venue_reservations_table.php',
            '2026_03_21_021945_create_event_approvals_table.php',
            '2026_04_01_000001_create_file_hunting_signatories_table.php',
            '2026_06_10_103302_create_attendance_audit_logs_table.php',
            '2026_06_10_103303_create_system_audit_logs_table.php',
            '2026_08_01_000001_add_checked_out_at_to_attendances_table.php',
            '2026_08_09_000001_split_name_into_separate_columns.php',
            '2026_08_12_000001_create_financial_and_proposal_tables.php',
            '2026_08_22_123432_add_checkout_photo_path_to_attendances_table.php',
            '2026_09_26_000001_connect_proposals_equipment_and_approvals.php',
            '2026_09_26_000002_link_reviewed_proposal_to_event_approvals.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function user(string $role = 'student', array $attributes = []): User
    {
        return User::create(array_replace([
            'first_name' => 'Review', 'surname' => 'User',
            'email' => uniqid('review-').'@example.test', 'password' => 'ReviewOnly!123',
            'role' => $role, 'is_active' => true, 'email_verified_at' => now(),
        ], $attributes));
    }

    protected function event(?User $organizer = null, array $attributes = []): Event
    {
        return Event::create(array_replace([
            'title' => 'Review event', 'venue' => 'Hall', 'category' => 'Academic',
            'event_date' => now()->addDay()->toDateString(), 'start_time' => '09:00',
            'end_time' => '12:00', 'capacity' => 100, 'status' => 'published',
            'organizer_id' => ($organizer ?? $this->user('organizer'))->id,
        ], $attributes));
    }
}
