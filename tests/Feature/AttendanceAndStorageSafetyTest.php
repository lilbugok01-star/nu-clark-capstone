<?php

namespace Tests\Feature;

use App\Helpers\StorageUrl;
use App\Models\Attendance;
use App\Models\EventPayment;
use App\Models\Registration;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\IsolatedDatabaseTestCase;

class AttendanceAndStorageSafetyTest extends IsolatedDatabaseTestCase
{
    private const PHOTO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jkxoAAAAASUVORK5CYII=';

    public function test_scanner_get_is_read_only_and_reposting_the_same_scan_does_not_check_out(): void
    {
        $organizer = $this->user('organizer');
        $event = $this->event($organizer, ['event_date' => now()->toDateString()]);
        $student = $this->user();
        $registration = Registration::create(['event_id' => $event->id, 'user_id' => $student->id, 'status' => 'confirmed']);
        $url = route('organizer.scan', $registration->qr_token);
        $this->actingAs($organizer)->get($url)->assertOk()->assertSee('Record Time In');
        $this->assertDatabaseCount('attendances', 0);
        $scan = ['scan_id' => (string) Str::uuid()];
        $this->post($url, $scan)->assertOk()->assertViewHas('status', 'time_in');
        $this->assertSame($organizer->id, Attendance::sole()->verified_by);
        $this->post($url, $scan)->assertOk()->assertViewHas('status', 'warning');
        $this->assertNull(Attendance::sole()->checked_out_at);
        $this->get($url)->assertOk()->assertSee('Record Time Out');
        $this->assertNull(Attendance::sole()->checked_out_at);
        $this->post($url, ['scan_id' => (string) Str::uuid()])->assertOk()->assertViewHas('status', 'time_out');
        $this->assertNotNull(Attendance::sole()->checked_out_at);
        $this->assertDatabaseCount('attendances', 1);
    }

    public function test_scanner_requires_csrf_in_production(): void
    {
        $this->actingAs($this->user('organizer'));
        $this->app['env'] = 'production';
        $this->post('/organizer/scan/test', ['scan_id' => (string) Str::uuid()])->assertStatus(419);
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_unauthorized_and_cancelled_scans_never_record_attendance(): void
    {
        $owner = $this->user('organizer');
        $event = $this->event($owner, ['event_date' => now()->toDateString()]);
        $registration = Registration::create(['event_id' => $event->id, 'user_id' => $this->user()->id, 'status' => 'confirmed']);
        $url = route('organizer.scan', $registration->qr_token);
        $this->actingAs($this->user('organizer'))->post($url, ['scan_id' => (string) Str::uuid()])->assertForbidden();
        $registration->update(['status' => 'cancelled']);
        $this->actingAs($owner)->post($url, ['scan_id' => (string) Str::uuid()])
            ->assertOk()->assertViewHas('status', 'error');
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_api_selfie_checkin_and_checkout_still_work_for_the_registration_owner(): void
    {
        Storage::fake('s3');
        $student = $this->user();
        $event = $this->event(attributes: ['event_date' => now()->toDateString()]);
        $registration = Registration::create(['event_id' => $event->id, 'user_id' => $student->id, 'status' => 'confirmed']);
        $payload = ['qr_token' => $registration->qr_token, 'photo_data' => self::PHOTO];
        $this->actingAs($this->user())->postJson('/api/attendance/checkin', $payload)->assertForbidden();
        $this->actingAs($student)->postJson('/api/attendance/checkin', $payload)->assertCreated()->assertJsonPath('scan_type', 'time_in');
        Storage::disk('s3')->assertExists(Attendance::sole()->photo_path);
        $this->postJson('/api/attendance/checkin', $payload)->assertOk()->assertJsonPath('scan_type', 'time_out');
        Storage::disk('s3')->assertExists(Attendance::sole()->checkout_photo_path);
        $this->assertDatabaseCount('attendances', 1);
    }

    public function test_student_web_checkin_cannot_use_someone_elses_qr(): void
    {
        Storage::fake('s3');
        $student = $this->user();
        $event = $this->event(attributes: ['event_date' => now()->toDateString()]);
        $registration = Registration::create(['event_id' => $event->id, 'user_id' => $student->id, 'status' => 'confirmed']);
        $payload = ['qr_token' => $registration->qr_token, 'photo_data' => self::PHOTO];
        $this->actingAs($this->user())->post('/student/attendance/checkin', $payload)->assertSessionHas('error');
        $this->assertDatabaseCount('attendances', 0);
        $this->actingAs($student)->post('/student/attendance/checkin', $payload)->assertRedirect(route('student.my-events'));
        $this->assertDatabaseCount('attendances', 1);
        $this->post("/student/attendance/checkout/{$registration->id}", ['photo_data' => self::PHOTO])
            ->assertRedirect(route('student.my-events'));
        $this->assertNotNull(Attendance::sole()->checked_out_at);
    }

    public function test_scanners_reject_submissions_after_the_late_window(): void
    {
        $owner = $this->user('organizer');
        $event = $this->event($owner, ['event_date' => now()->toDateString(), 'start_time' => '05:00', 'end_time' => '06:00']);
        $student = $this->user();
        $registration = Registration::create(['event_id' => $event->id, 'user_id' => $student->id, 'status' => 'confirmed']);
        $this->actingAs($owner)->post(route('organizer.scan', $registration->qr_token), ['scan_id' => (string) Str::uuid()])
            ->assertOk()->assertViewHas('status', 'error');
        $this->actingAs($student)->postJson('/api/attendance/checkin', ['qr_token' => $registration->qr_token, 'photo_data' => self::PHOTO])
            ->assertUnprocessable();
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_photo_proxy_allows_only_the_student_event_owner_or_admin(): void
    {
        Storage::fake('s3');
        Storage::fake('public');
        Storage::fake('local');
        $student = $this->user();
        $owner = $this->user('organizer');
        $event = $this->event($owner);
        $registration = Registration::create(['event_id' => $event->id, 'user_id' => $student->id]);
        $path = "attendance-photos/{$event->id}/test.png";
        Attendance::create(['registration_id' => $registration->id, 'photo_path' => $path, 'status' => 'pending']);
        Storage::disk('s3')->put($path, 'private photo');
        $url = StorageUrl::url($path);
        $this->get($url)->assertForbidden();
        $this->actingAs($this->user())->get($url)->assertForbidden();
        $this->actingAs($this->user('organizer'))->get($url)->assertForbidden();
        foreach ([$student, $owner, $this->user('admin')] as $allowed) {
            $response = $this->actingAs($allowed)->get($url)->assertOk()->assertSee('private photo');
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        }
    }

    public function test_receipts_are_private_but_posters_stay_public(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('s3');
        $owner = $this->user('organizer');
        $event = $this->event($owner);
        $path = 'receipts/test.pdf';
        EventPayment::create(['event_id' => $event->id, 'recorded_by' => $owner->id, 'receipt_path' => $path,
            'payment_type' => 'expense', 'amount' => 10, 'description' => 'Test', 'payment_date' => now()]);
        Storage::disk('local')->put($path, 'private receipt');
        $url = StorageUrl::url($path);
        $this->get($url)->assertForbidden();
        $this->actingAs($this->user('organizer'))->get($url)->assertForbidden();
        $this->actingAs($owner)->get($url)->assertOk();
        $this->actingAs($this->user('admin'))->get($url)->assertOk();
        Storage::disk('s3')->put('posters/test.png', 'public poster');
        auth()->logout();
        $this->get(route('storage.s3', ['path' => 'posters/test.png']))->assertOk()->assertSee('public poster');
        $this->get(route('storage.s3', ['path' => 'posters/missing.png']))->assertNotFound();
    }

    public function test_previous_event_filters_reject_invalid_or_unbounded_years(): void
    {
        $this->actingAs($this->user('admin'));
        $url = route('admin.previous-events.index');
        $this->get($url.'?year=1999')->assertSessionHasErrors('year');
        $this->get($url.'?year=not-a-year')->assertSessionHasErrors('year');
        $this->get($url.'?category='.str_repeat('x', 101))->assertSessionHasErrors('category');
    }
}
