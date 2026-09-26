<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventProposal;
use App\Models\EventBudget;
use App\Models\EventApproval;
use App\Models\User;
use App\Services\PreviousEventComparisonService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Validation\ValidationException;

class ProposalController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            'auth',
            new Middleware(function ($request, $next) {
                if (!Auth::check() || !in_array(Auth::user()->role, ['admin', 'organizer', 'student_development', 'adviser', 'department_head', 'dean', 'executive_director', 'program_chair'])) {
                    abort(403);
                }
                return $next($request);
            }),
        ];
    }

    // List all proposals (admin sees all, organizer sees only their events' proposals)
    public function index() {
        $user = Auth::user();
        if ($user->role === 'admin') {
            $proposals = EventProposal::with('event', 'preparedBy')->orderByDesc('created_at')->paginate(15);
        } else {
            $eventIds = Event::where('organizer_id', $user->id)->pluck('id');
            $proposals = EventProposal::with('event', 'preparedBy')->whereIn('event_id', $eventIds)->orderByDesc('created_at')->paginate(15);
        }
        return view('organizer.proposals', compact('proposals'));
    }

    // Show form to create proposal for an event
    public function create($eventId, PreviousEventComparisonService $comparisonService) {
        $event = Event::findOrFail($eventId);
        // Check authorization for non-admin
        if (Auth::user()->role !== 'admin' && $event->organizer_id !== Auth::id()) {
            abort(403);
        }
        $existing = $event->proposals()->latest()->first();
        if ($existing) {
            return redirect()->route('proposal.show', $existing)
                ->with('error', 'This event already has an active proposal. Edit or review that proposal instead of creating a duplicate.');
        }
        $event->load('equipmentRequests');
        $budgetItems = EventBudget::where('event_id', $eventId)->get();
        $estimatedBudget = $budgetItems->sum('estimated_amount');
        $previousEvents = $comparisonService->compare($event);
        $recommendations = $comparisonService->recommendations($event, $previousEvents);
        return view('organizer.proposal-form', compact('event', 'budgetItems', 'estimatedBudget', 'previousEvents', 'recommendations'));
    }

    public function edit($id, PreviousEventComparisonService $comparisonService)
    {
        $proposal = EventProposal::with('event.equipmentRequests')->findOrFail($id);
        Gate::authorize('update', $proposal->event);
        abort_unless(in_array($proposal->status, ['draft', 'rejected'], true), 422, 'Only draft or rejected proposals can be edited.');

        $event = $proposal->event;
        $budgetItems = EventBudget::where('event_id', $event->id)->get();
        $estimatedBudget = $budgetItems->sum('estimated_amount');
        $previousEvents = $comparisonService->compare($event);
        $recommendations = $comparisonService->recommendations($event, $previousEvents);

        return view('organizer.proposal-form', compact('proposal', 'event', 'budgetItems', 'estimatedBudget', 'previousEvents', 'recommendations'));
    }

    // Store new proposal
    public function store(Request $request) {
        $request->validate(['event_id' => 'required|exists:events,id']);
        Gate::authorize('update', Event::findOrFail($request->integer('event_id')));

        if (EventProposal::where('event_id', $request->integer('event_id'))->exists()) {
            throw ValidationException::withMessages(['event_id' => 'This event already has a proposal. Revise the existing proposal instead.']);
        }

        $validated = $request->validate([
            'event_id' => 'required|exists:events,id',
            'event_overview' => 'required|string',
            'objectives' => 'required|string',
            'target_audience' => 'nullable|string',
            'estimated_budget' => 'required|numeric|min:0',
            'venue_details' => 'nullable|string',
            'schedule_details' => 'nullable|string',
            'requirements' => 'nullable|string',
            'expected_outcomes' => 'nullable|string',
            'recommendations' => 'required|string|max:10000',
            'recommendations_applied' => 'accepted',
        ]);

        unset($validated['recommendations_applied']);
        $proposal = EventProposal::create([
            ...$validated,
            'prepared_by' => Auth::id(),
            'proposal_number' => EventProposal::generateNumber(),
            'status' => 'draft',
            'recommendations_applied_at' => now(),
        ]);

        User::log('create_proposal', $proposal, null, $proposal->toArray());
        return redirect()->route('proposal.show', $proposal->id)->with('success', 'Proposal created successfully!');
    }

    public function update(Request $request, $id)
    {
        $proposal = EventProposal::findOrFail($id);
        Gate::authorize('update', $proposal->event);
        abort_unless(in_array($proposal->status, ['draft', 'rejected'], true), 422, 'Only draft or rejected proposals can be edited.');

        $validated = $request->validate([
            'event_overview' => 'required|string',
            'objectives' => 'required|string',
            'target_audience' => 'nullable|string',
            'estimated_budget' => 'required|numeric|min:0',
            'venue_details' => 'nullable|string',
            'schedule_details' => 'nullable|string',
            'requirements' => 'nullable|string',
            'expected_outcomes' => 'nullable|string',
            'recommendations' => 'required|string|max:10000',
            'recommendations_applied' => 'accepted',
        ]);

        unset($validated['recommendations_applied']);
        $old = $proposal->toArray();
        $proposal->update([
            ...$validated,
            'status' => 'draft',
            'recommendations_applied_at' => now(),
            'rejection_reason' => null,
            'approved_by' => null,
            'approved_at' => null,
        ]);
        EventApproval::where('event_id', $proposal->event_id)->update([
            'status' => 'pending',
            'comments' => null,
            'e_signature_used' => null,
            'proposal_reviewed_at' => null,
            'reviewed_proposal_id' => null,
        ]);
        User::log('update_proposal', $proposal, $old, $proposal->toArray());

        return redirect()->route('proposal.show', $proposal)->with('success', 'Proposal revisions saved. Review it, then resubmit for approval.');
    }

    // View proposal detail
    public function show($id) {
        $proposal = EventProposal::with('event.equipmentRequests.requestedBy', 'preparedBy', 'approvedBy')->findOrFail($id);
        $this->authorizeProposalView($proposal);
        return view('organizer.proposal-view', compact('proposal'));
    }

    // Submit proposal for review
    public function submit($id) {
        $proposal = EventProposal::findOrFail($id);
        Gate::authorize('update', $proposal->event);
        abort_unless($proposal->status === 'draft', 422, 'Rejected proposals must be revised and saved before resubmission.');
        if ($proposal->recommendations !== null) {
            abort_unless($proposal->recommendations_applied_at, 422, 'Confirm how the previous-event recommendations were applied before submitting.');
        }
        $old = $proposal->toArray();
        $proposal->update(['status' => 'submitted', 'rejection_reason' => null, 'approved_by' => null, 'approved_at' => null]);
        User::log('submit_proposal', $proposal, $old, $proposal->toArray());
        return back()->with('success', 'Proposal submitted for review.');
    }

    // Admin approve
    public function approve(Request $request, $id) {
        if (Auth::user()->role !== 'admin') abort(403);
        $proposal = EventProposal::findOrFail($id);
        abort_unless($proposal->status === 'submitted', 422, 'Only submitted proposals can be approved.');
        $old = $proposal->toArray();
        $proposal->update([
            'status' => 'approved',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);
        if (in_array($proposal->event->status, ['draft', 'rejected'], true)) {
            $proposal->event->update(['status' => 'pending_adviser']);
        }
        User::log('approve_proposal', $proposal, $old, $proposal->toArray());
        return back()->with('success', 'Proposal approved.');
    }

    // Admin reject
    public function reject(Request $request, $id) {
        if (Auth::user()->role !== 'admin') abort(403);
        $request->validate(['rejection_reason' => 'required|string|max:1000']);
        $proposal = EventProposal::findOrFail($id);
        abort_unless($proposal->status === 'submitted', 422, 'Only submitted proposals can be rejected.');
        $old = $proposal->toArray();
        $proposal->update([
            'status' => 'rejected',
            'rejection_reason' => $request->rejection_reason,
        ]);
        User::log('reject_proposal', $proposal, $old, $proposal->toArray());
        return back()->with('success', 'Proposal rejected.');
    }

    // Export as PDF
    public function exportPdf($id) {
        $proposal = EventProposal::with('event.equipmentRequests.requestedBy', 'preparedBy', 'approvedBy')->findOrFail($id);
        $this->authorizeProposalView($proposal);
        $budgetItems = EventBudget::where('event_id', $proposal->event_id)->get();
        $pdf = Pdf::loadView('reports.proposal-pdf', compact('proposal', 'budgetItems'));
        $response = $pdf->download('proposal-' . $proposal->proposal_number . '.pdf');
        $this->recordSignatoryEvidenceOpen($proposal);
        User::log('export_proposal_pdf', $proposal, null, ['format' => 'pdf']);

        return $response;
    }

    private function recordSignatoryEvidenceOpen(EventProposal $proposal): void
    {
        $user = Auth::user();
        $eventRoles = ['adviser', 'department_head', 'dean', 'executive_director'];
        if ($proposal->status !== 'approved' || !in_array($user->role, $eventRoles, true)) {
            return;
        }

        $approval = EventApproval::firstOrNew([
            'event_id' => $proposal->event_id,
            'role_level' => $user->role,
        ]);
        $alreadyApprovedThisEvidence = $approval->exists
            && $approval->status === 'approved'
            && $approval->approver_id === $user->id
            && $approval->reviewed_proposal_id === $proposal->id;
        $approval->approver_id = $user->id;
        $approval->status = $alreadyApprovedThisEvidence ? 'approved' : 'pending';
        $approval->proposal_reviewed_at = now();
        $approval->reviewed_proposal_id = $proposal->id;
        $approval->save();
    }

    private function authorizeProposalView(EventProposal $proposal): void
    {
        $user = Auth::user();
        $signatoryRoles = ['adviser', 'department_head', 'dean', 'executive_director', 'student_development', 'program_chair'];
        abort_unless(
            $user->role === 'admin' || $proposal->event->organizer_id === $user->id ||
            ($proposal->status === 'approved' && in_array($user->role, $signatoryRoles, true)),
            403
        );
    }
}
