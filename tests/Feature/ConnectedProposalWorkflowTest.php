<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventApproval;
use App\Models\EventProposal;
use App\Services\PreviousEventComparisonService;
use Tests\Support\IsolatedDatabaseTestCase;

class ConnectedProposalWorkflowTest extends IsolatedDatabaseTestCase
{
    public function test_event_equipment_proposal_and_signatory_evidence_are_one_enforced_flow(): void
    {
        $organizer = $this->user('organizer');

        $response = $this->actingAs($organizer)->post(route('organizer.event.store'), [
            'title' => 'Student Technology Summit',
            'description' => 'A technology leadership summit and workshop for students.',
            'venue_type' => 'NU Clark Auditorium',
            'venue' => 'NU Clark Auditorium',
            'event_date' => now()->addMonth()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '12:00',
            'capacity' => 150,
            'category' => 'Technology',
            'equipment_items' => [
                ['item_name' => 'Projector', 'quantity' => 2, 'purpose' => 'Main stage and overflow room'],
                ['item_name' => 'Wireless microphone', 'quantity' => 3, 'purpose' => 'Speakers and questions'],
            ],
        ]);

        $event = Event::where('title', 'Student Technology Summit')->firstOrFail();
        $response->assertRedirect(route('proposal.create', $event));
        $this->assertSame('draft', $event->status);
        $this->assertDatabaseHas('equipment_requests', ['event_id' => $event->id, 'item_name' => 'Projector', 'quantity' => 2]);

        $this->actingAs($organizer)->get(route('proposal.create', $event))
            ->assertOk()->assertSee('Previous-Event Recommendations')->assertSee('Projector');

        $this->post(route('proposal.store'), [
            'event_id' => $event->id,
            'event_overview' => $event->description,
            'objectives' => 'Improve practical technology and leadership skills.',
            'estimated_budget' => 25000,
            'recommendations' => 'Use the documented attendance and equipment checklist as planning references.',
            'recommendations_applied' => '1',
        ])->assertRedirect();

        $proposal = EventProposal::where('event_id', $event->id)->firstOrFail();
        $this->assertNotNull($proposal->recommendations_applied_at);
        $this->post(route('proposal.submit', $proposal))->assertRedirect();

        $admin = $this->user('admin');
        $this->actingAs($admin)->post(route('proposal.approve', $proposal))->assertRedirect();
        $this->assertSame('approved', $proposal->fresh()->status);
        $this->assertSame('pending_adviser', $event->fresh()->status);

        $adviser = $this->user('adviser', ['e_signature_path' => 'signatures/adviser.png']);
        $this->actingAs($adviser)->post(route('approver.events.approve', $event), [])
            ->assertSessionHasErrors('proposal_reviewed');
        $this->post(route('approver.events.approve', $event), ['proposal_reviewed' => '1'])
            ->assertSessionHas('error', 'Open the approved proposal evidence before approving this event.');
        $this->get(route('proposal.export-pdf', $proposal))->assertOk();
        $this->post(route('approver.events.approve', $event), ['proposal_reviewed' => '1'])
            ->assertRedirect();

        $approval = EventApproval::where('event_id', $event->id)->where('role_level', 'adviser')->firstOrFail();
        $this->assertNotNull($approval->proposal_reviewed_at);
        $this->assertSame($proposal->id, $approval->reviewed_proposal_id);
        $this->assertSame('pending_dept_head', $event->fresh()->status);
    }

    public function test_rejected_event_proposal_is_revised_before_it_can_reenter_approval(): void
    {
        $organizer = $this->user('organizer');
        $event = $this->event($organizer, ['status' => 'pending_adviser']);
        $admin = $this->user('admin');
        $proposal = EventProposal::create([
            'event_id' => $event->id,
            'prepared_by' => $organizer->id,
            'proposal_number' => 'PROP-REVISION-0001',
            'status' => 'approved',
            'event_overview' => 'Original overview',
            'objectives' => 'Original objective',
            'estimated_budget' => 12000,
            'recommendations' => 'Use the previous event checklist.',
            'recommendations_applied_at' => now(),
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);
        $adviser = $this->user('adviser', ['e_signature_path' => 'signatures/adviser.png']);

        $this->actingAs($adviser)->post(route('approver.events.reject', $event), [
            'comments' => 'Clarify the equipment plan and reduce the estimated cost.',
        ])->assertRedirect();
        $this->assertSame('rejected', $event->fresh()->status);
        $this->assertSame('rejected', $proposal->fresh()->status);

        $this->actingAs($organizer)->get(route('proposal.edit', $proposal))->assertOk()->assertSee('Revision required');
        $this->put(route('proposal.update', $proposal), [
            'event_overview' => 'Revised overview with an equipment plan.',
            'objectives' => 'Revised objective',
            'estimated_budget' => 10000,
            'requirements' => 'One projector and two microphones.',
            'recommendations' => 'Applied the previous-event equipment checklist and reduced cost.',
            'recommendations_applied' => '1',
        ])->assertRedirect(route('proposal.show', $proposal));

        $this->assertSame('draft', $proposal->fresh()->status);
        $this->assertDatabaseHas('event_approvals', [
            'event_id' => $event->id,
            'role_level' => 'adviser',
            'status' => 'pending',
            'reviewed_proposal_id' => null,
        ]);
        $this->post(route('proposal.submit', $proposal))->assertRedirect();
        $this->assertSame('submitted', $proposal->fresh()->status);
    }

    public function test_previous_event_comparison_uses_recorded_events_not_forecasts(): void
    {
        $organizer = $this->user('organizer');
        $previous = $this->event($organizer, [
            'title' => 'Technology Leadership Workshop 2025',
            'description' => 'A practical technology leadership workshop for students.',
            'category' => 'Technology', 'venue' => 'NU Clark Auditorium',
            'event_date' => now()->subYear()->toDateString(), 'status' => 'completed',
        ]);
        $current = $this->event($organizer, [
            'title' => 'Student Technology Leadership Workshop',
            'description' => 'A technology leadership workshop for students.',
            'category' => 'Technology', 'venue' => 'NU Clark Auditorium',
            'event_date' => now()->addMonth()->toDateString(), 'status' => 'draft',
        ]);

        $matches = app(PreviousEventComparisonService::class)->compare($current);
        $this->assertSame($previous->id, $matches->first()->id);
        $this->assertGreaterThan(50, $matches->first()->comparison_score);
        $this->assertStringContainsString('closest documentary reference', implode(' ', app(PreviousEventComparisonService::class)->recommendations($current, $matches)));
    }

    public function test_management_reports_and_sql_payload_validation_are_available(): void
    {
        $admin = $this->user('admin', ['email' => 'admin@example.test']);
        foreach (['events', 'budget-event', 'proposals', 'year-end-audit', 'liquidation'] as $type) {
            $this->actingAs($admin)->get(route('admin.reports', ['type' => $type, 'year' => now()->year]))->assertOk();
        }

        auth()->logout();
        $this->post(route('login'), ['email' => "admin@example.test' OR 1=1 --", 'password' => 'anything'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->postJson('/api/login', ['email' => "admin@example.test' OR 1=1 --", 'password' => 'anything'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 1);
    }
}
