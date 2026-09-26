<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventProposal;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class ManagementReportController extends Controller
{
    private const TYPES = ['events', 'budget-event', 'proposals', 'year-end-audit', 'liquidation'];

    public function index(Request $request)
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(self::TYPES)],
            'year' => ['nullable', 'integer', 'min:2000', 'max:' . (now()->year + 1)],
        ]);
        $type = $filters['type'] ?? 'events';
        $year = (int) ($filters['year'] ?? now()->year);
        $report = $this->build($type, $year);
        $years = $this->availableYears();

        return view('admin.reports', compact('type', 'year', 'report', 'years'));
    }

    public function export(Request $request, string $type)
    {
        abort_unless(in_array($type, self::TYPES, true), 404);
        $validated = $request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:' . (now()->year + 1)]]);
        $year = (int) ($validated['year'] ?? now()->year);
        $report = $this->build($type, $year);
        User::log('export_management_report', null, null, ['type' => $type, 'year' => $year]);

        return Pdf::loadView('reports.management-pdf', compact('type', 'year', 'report'))
            ->setPaper('a4', 'landscape')->download("{$type}-{$year}.pdf");
    }

    public function exportEvents(Request $request)
    {
        return $this->export($request, 'events');
    }

    private function build(string $type, int $year): array
    {
        return match ($type) {
            'budget-event' => $this->budgetAndEvent($year),
            'proposals' => $this->proposals($year),
            'year-end-audit' => $this->yearEndAudit($year),
            'liquidation' => $this->liquidation($year),
            default => $this->events($year),
        };
    }

    private function baseEvents(int $year)
    {
        return Event::query()->whereYear('event_date', $year)->with('organizer');
    }

    private function events(int $year): array
    {
        $events = $this->baseEvents($year)->withCount([
            'registrations as registrations_count' => fn ($query) => $query->where('status', '!=', 'cancelled'),
            'registrations as attended_count' => fn ($query) => $query->whereHas('attendance', fn ($attendance) => $attendance->where('status', 'verified')),
        ])->orderBy('event_date')->get();

        return $this->pack('Event Report', ['Event', 'Date', 'Organizer', 'Status', 'Registered', 'Attended'], $events->map(fn ($event) => [
            $event->title, $event->event_date->format('M d, Y'), $event->organizer?->full_name ?? '-',
            ucfirst(str_replace('_', ' ', $event->status)), $event->registrations_count, $event->attended_count,
        ]), ['Events' => $events->count(), 'Registrations' => $events->sum('registrations_count'), 'Verified attendance' => $events->sum('attended_count')]);
    }

    private function budgetAndEvent(int $year): array
    {
        $events = $this->baseEvents($year)->with(['budgetItems', 'payments'])->orderBy('event_date')->get();
        $rows = $events->map(function ($event) {
            $estimated = (float) $event->budgetItems->sum('estimated_amount');
            $actual = (float) $event->budgetItems->sum('actual_amount');
            $expenses = (float) $event->payments->where('payment_type', 'expense')->sum('amount');
            return [$event->title, $event->event_date->format('M d, Y'), $this->money($estimated), $this->money($actual), $this->money($expenses), $this->money($estimated - max($actual, $expenses))];
        });
        return $this->pack('Budget and Event Report', ['Event', 'Date', 'Estimated', 'Actual budget', 'Recorded expenses', 'Variance'], $rows, [
            'Events' => $events->count(),
            'Estimated budget' => $this->money($events->sum(fn ($event) => $event->budgetItems->sum('estimated_amount'))),
            'Recorded expenses' => $this->money($events->sum(fn ($event) => $event->payments->where('payment_type', 'expense')->sum('amount'))),
        ]);
    }

    private function proposals(int $year): array
    {
        $proposals = EventProposal::with(['event', 'preparedBy', 'approvedBy'])
            ->whereHas('event', fn ($query) => $query->whereYear('event_date', $year))->orderBy('created_at')->get();
        return $this->pack('Proposal Report per Year', ['Proposal', 'Event', 'Prepared by', 'Status', 'Budget', 'Approved date'], $proposals->map(fn ($proposal) => [
            $proposal->proposal_number, $proposal->event?->title ?? '-', $proposal->preparedBy?->full_name ?? '-',
            ucfirst($proposal->status), $this->money($proposal->estimated_budget), $proposal->approved_at?->format('M d, Y') ?? '-',
        ]), ['Proposals' => $proposals->count(), 'Approved' => $proposals->where('status', 'approved')->count(), 'Proposed budget' => $this->money($proposals->sum('estimated_budget'))]);
    }

    private function yearEndAudit(int $year): array
    {
        $events = $this->baseEvents($year)->with(['approvedProposal', 'approvals', 'payments'])->withCount([
            'registrations as registrations_count' => fn ($query) => $query->where('status', '!=', 'cancelled'),
            'registrations as attended_count' => fn ($query) => $query->whereHas('attendance', fn ($attendance) => $attendance->where('status', 'verified')),
        ])->orderBy('event_date')->get();
        return $this->pack('Year-End Audit of All Events', ['Event', 'Status', 'Proposal evidence', 'Sign-offs', 'Registered', 'Attended', 'Expenses'], $events->map(fn ($event) => [
            $event->title, ucfirst(str_replace('_', ' ', $event->status)), $event->approvedProposal?->proposal_number ?? 'Missing',
            $event->approvals->where('status', 'approved')->count(), $event->registrations_count, $event->attended_count,
            $this->money($event->payments->where('payment_type', 'expense')->sum('amount')),
        ]), ['All events' => $events->count(), 'Completed/published' => $events->whereIn('status', ['completed', 'published'])->count(), 'With approved proposal' => $events->filter(fn ($event) => $event->approvedProposal)->count(), 'Total attendance' => $events->sum('attended_count')]);
    }

    private function liquidation(int $year): array
    {
        $events = $this->baseEvents($year)->with(['budgetItems', 'payments'])->orderBy('event_date')->get();
        $rows = $events->map(function ($event) {
            $released = (float) $event->budgetItems->whereIn('status', ['approved', 'spent'])->sum('estimated_amount');
            $expenses = $event->payments->where('payment_type', 'expense');
            $liquidated = (float) $expenses->sum('amount');
            $receipts = $expenses->whereNotNull('receipt_path')->count();
            $balance = $released - $liquidated;
            $status = match (true) {
                $released <= 0 || $expenses->isEmpty() => 'For review',
                $receipts !== $expenses->count() => 'Missing receipts',
                abs($balance) >= 0.01 => 'Outstanding balance',
                default => 'Complete',
            };
            return [$event->title, $this->money($released), $this->money($liquidated), $this->money($balance), "{$receipts}/{$expenses->count()}", $status];
        });
        return $this->pack('Liquidation Report', ['Event', 'Approved/released', 'Liquidated expenses', 'Balance', 'Receipts', 'Review status'], $rows, [
            'Events reviewed' => $events->count(),
            'Released budget' => $this->money($events->sum(fn ($event) => $event->budgetItems->whereIn('status', ['approved', 'spent'])->sum('estimated_amount'))),
            'Liquidated expenses' => $this->money($events->sum(fn ($event) => $event->payments->where('payment_type', 'expense')->sum('amount'))),
        ]);
    }

    private function pack(string $title, array $headers, Collection $rows, array $summary): array
    {
        return compact('title', 'headers', 'rows', 'summary');
    }

    private function availableYears(): Collection
    {
        return Event::orderByDesc('event_date')->pluck('event_date')->map(fn ($date) => (int) substr((string) $date, 0, 4))
            ->prepend(now()->year)->unique()->sortDesc()->values();
    }

    private function money(float|int|string|null $amount): string
    {
        return 'PHP ' . number_format((float) $amount, 2);
    }
}
