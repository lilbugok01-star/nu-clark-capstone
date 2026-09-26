<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\EventProposal;
use App\Models\FileHuntingSignatory;
use App\Models\Registration;
use Illuminate\Support\Facades\DB;
use Tests\Support\IsolatedDatabaseTestCase;

class SystemSafetyRegressionTest extends IsolatedDatabaseTestCase
{
    public function test_viewing_login_never_creates_accounts(): void
    {
        $this->get('/login')->assertOk();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_cannot_reset_an_existing_administrator_password(): void
    {
        $admin = $this->user('admin', ['email' => 'admin@nu-clark.edu.ph']);
        $hash = $admin->password;
        // Regression input from the removed fallback, not a provisioned account.
        $this->post('/login', ['email' => $admin->email, 'password' => 'Password123@'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame($hash, $admin->fresh()->password);
        $this->post('/login', ['email' => $admin->email, 'password' => 'ReviewOnly!123'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_database_setup_endpoints_are_disabled_outside_local_development(): void
    {
        $this->get('/seed-db')->assertNotFound();
        $this->assertDatabaseCount('users', 0);
        $this->actingAs($this->user('admin'))->post('/force-sync-database')->assertNotFound();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_public_event_responses_do_not_expose_registrations_or_private_user_fields(): void
    {
        $event = $this->event();
        $student = $this->user();
        $registration = Registration::create(['event_id' => $event->id, 'user_id' => $student->id, 'status' => 'confirmed']);
        foreach (["/api/events/{$event->id}", '/api/events', '/api/events/upcoming'] as $url) {
            $response = $this->getJson($url)->assertOk();
            $response->assertDontSee($registration->qr_token)->assertDontSee($student->email)
                ->assertDontSee($event->organizer->email);
        }
        $this->getJson("/api/events/{$event->id}")->assertJsonPath('registered_count', 1)
            ->assertJsonMissingPath('registrations')->assertJsonMissingPath('organizer.email');
    }

    public function test_public_event_detail_rejects_drafts_and_preserves_published_pages(): void
    {
        $event = $this->event(attributes: ['status' => 'draft']);
        $this->getJson("/api/events/{$event->id}")->assertNotFound();
        $this->get("/events/{$event->id}")->assertNotFound();
        $event->update(['status' => 'published']);
        $this->get("/events/{$event->id}")->assertOk();
        $event->update(['status' => 'completed']);
        $this->getJson("/api/events/{$event->id}")->assertOk();
    }

    public function test_qr_information_and_images_are_limited_to_the_owner_event_organizer_and_admin(): void
    {
        $student = $this->user();
        $organizer = $this->user('organizer');
        $event = $this->event($organizer);
        $registration = Registration::create(['event_id' => $event->id, 'user_id' => $student->id, 'status' => 'confirmed']);
        foreach ([$this->user(), $this->user('organizer')] as $outsider) {
            $this->actingAs($outsider)->getJson("/api/qrcode/{$registration->id}/info")->assertForbidden();
            $this->getJson("/api/qrcode/{$registration->id}")->assertForbidden();
        }
        foreach ([$student, $organizer, $this->user('admin')] as $allowed) {
            $this->actingAs($allowed)->getJson("/api/qrcode/{$registration->id}/info")->assertOk();
            $this->get("/api/qrcode/{$registration->id}")->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        }
    }

    public function test_organizers_cannot_read_another_events_attendance_or_exports(): void
    {
        $organizer = $this->user('organizer');
        $event = $this->event($organizer);
        $registration = Registration::create(['event_id' => $event->id, 'user_id' => $this->user()->id]);
        $attendance = Attendance::create(['registration_id' => $registration->id, 'status' => 'pending']);
        $this->actingAs($this->user('organizer'));
        foreach (["/api/events/{$event->id}/attendance", "/api/attendance/{$attendance->id}",
            "/api/reports/attendance/{$event->id}", "/api/reports/attendance/{$event->id}/pdf",
            "/api/reports/attendance/{$event->id}/excel"] as $url) {
            $this->getJson($url)->assertForbidden();
        }
        $this->getJson('/api/reports/events')->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($organizer)->getJson("/api/events/{$event->id}/attendance")->assertOk();
        $this->getJson("/api/reports/attendance/{$event->id}")->assertOk();
        $this->getJson('/api/reports/events')->assertJsonPath('total', 1);
    }

    public function test_proposal_access_and_submission_require_event_ownership(): void
    {
        $owner = $this->user('organizer');
        $event = $this->event($owner);
        $proposal = EventProposal::create(['event_id' => $event->id, 'prepared_by' => $owner->id,
            'proposal_number' => 'TEST-1', 'status' => 'draft']);
        $this->actingAs($this->user('organizer'));
        $this->get("/proposals/{$proposal->id}")->assertForbidden();
        $this->get("/proposals/{$proposal->id}/export-pdf")->assertForbidden();
        $this->post("/proposals/{$proposal->id}/submit")->assertForbidden();
        $this->post('/proposals', ['event_id' => $event->id, 'event_overview' => 'Test',
            'objectives' => 'Test', 'estimated_budget' => 0])->assertForbidden();
        $this->assertSame('draft', $proposal->fresh()->status);
        $this->actingAs($owner)->get("/proposals/{$proposal->id}")->assertOk();
        $this->post("/proposals/{$proposal->id}/submit")->assertRedirect();
        $this->assertSame('submitted', $proposal->fresh()->status);
        $this->actingAs($this->user('admin'))->post("/proposals/{$proposal->id}/approve")->assertRedirect();
        $this->assertSame('approved', $proposal->fresh()->status);
        $this->actingAs($owner)->post("/proposals/{$proposal->id}/submit")->assertUnprocessable();
        $this->assertSame('approved', $proposal->fresh()->status);
    }

    public function test_web_cancel_and_reregister_reuses_the_record_and_rotates_the_qr_token(): void
    {
        $student = $this->user();
        $event = $this->event();
        $this->actingAs($student)->post("/student/events/{$event->id}/register")->assertRedirect(route('student.my-events'));
        $registration = Registration::sole();
        $oldToken = $registration->qr_token;
        $this->delete("/student/registration/{$registration->id}")->assertRedirect();
        $this->post("/student/events/{$event->id}/register")->assertRedirect(route('student.my-events'));
        $this->assertDatabaseCount('registrations', 1);
        $this->assertSame('confirmed', $registration->fresh()->status);
        $this->assertNotSame($oldToken, $registration->fresh()->qr_token);
    }

    public function test_api_cancel_and_reregister_reuses_the_record(): void
    {
        $student = $this->user();
        $event = $this->event();
        $this->actingAs($student)->postJson("/api/events/{$event->id}/register")->assertCreated();
        $registration = Registration::sole();
        $this->deleteJson("/api/registration/{$registration->id}")->assertOk();
        $this->postJson("/api/events/{$event->id}/register")->assertCreated();
        $this->assertDatabaseCount('registrations', 1);
        $this->postJson("/api/events/{$event->id}/register")->assertUnprocessable();
    }

    public function test_full_and_past_events_do_not_accept_new_registrations(): void
    {
        $event = $this->event(attributes: ['capacity' => 1]);
        $this->actingAs($this->user())->postJson("/api/events/{$event->id}/register")->assertCreated();
        $this->actingAs($this->user())->postJson("/api/events/{$event->id}/register")->assertUnprocessable();
        $event->update(['event_date' => now()->subDay()->toDateString()]);
        $this->postJson("/api/events/{$event->id}/register")->assertUnprocessable();
        $this->assertDatabaseCount('registrations', 1);
    }

    public function test_cancelled_registration_with_attendance_is_not_silently_reactivated(): void
    {
        $student = $this->user();
        $event = $this->event();
        $registration = Registration::create(['user_id' => $student->id, 'event_id' => $event->id, 'status' => 'cancelled']);
        Attendance::create(['registration_id' => $registration->id, 'status' => 'verified']);
        $this->actingAs($student)->postJson("/api/events/{$event->id}/register")->assertUnprocessable();
        $this->assertSame('cancelled', $registration->fresh()->status);
        $this->assertDatabaseCount('attendances', 1);
    }

    public function test_signatory_save_honors_false_and_uses_rollback_safe_statements(): void
    {
        DB::enableQueryLog();
        $this->actingAs($this->user('admin'))->post(route('admin.file-hunting.save'), ['signatories' => [
            ['role' => 'dean', 'position_label' => 'Dean', 'is_active' => false],
            ['role' => 'program_chair', 'position_label' => 'Chair', 'is_active' => true],
        ]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('file_hunting_signatories', 2);
        $this->assertFalse(FileHuntingSignatory::where('role', 'dean')->first()->is_active);
        $this->assertTrue(FileHuntingSignatory::where('role', 'program_chair')->first()->is_active);
        foreach (DB::getQueryLog() as $query) {
            $this->assertStringNotContainsString('truncate', strtolower($query['query']));
        }
        DB::disableQueryLog();
    }

    public function test_invalid_signatories_leave_the_existing_chain_unchanged(): void
    {
        $before = FileHuntingSignatory::all()->toArray();
        $this->actingAs($this->user('admin'))->post(route('admin.file-hunting.save'), ['signatories' => [
            ['role' => 'not_a_role', 'position_label' => 'Invalid'],
        ]])->assertSessionHasErrors('signatories.0.role');
        $this->assertSame($before, FileHuntingSignatory::all()->toArray());
    }

    public function test_malformed_selfies_are_rejected_before_any_attendance_is_written(): void
    {
        $this->actingAs($this->user());
        foreach (['not-an-image', 'data:image/png;base64,'.base64_encode('not image bytes')] as $photo) {
            $this->postJson('/api/attendance/checkin', ['qr_token' => 'test-token', 'photo_data' => $photo])
                ->assertUnprocessable()->assertJsonValidationErrors('photo_data');
            $this->post('/student/attendance/checkin', ['qr_token' => 'test-token', 'photo_data' => $photo])
                ->assertSessionHasErrors('photo_data');
        }
        $this->assertDatabaseCount('attendances', 0);
    }
}
