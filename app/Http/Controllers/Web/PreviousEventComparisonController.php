<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\PreviousEventComparisonService;
use Illuminate\Http\Request;

class PreviousEventComparisonController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:' . now()->year],
            'category' => ['nullable', 'string', 'max:100'],
        ]);

        $events = Event::query()
            ->whereDate('event_date', '<', today())
            ->whereIn('status', ['published', 'completed'])
            ->when($validated['year'] ?? null, fn ($query, $year) => $query->whereYear('event_date', $year))
            ->when($validated['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->withCount([
                'registrations as registrations_count' => fn ($query) => $query->where('status', '!=', 'cancelled'),
                'registrations as attended_count' => fn ($query) => $query->whereHas('attendance', fn ($attendance) => $attendance->where('status', 'verified')),
            ])
            ->with(['budgetItems', 'equipmentRequests'])
            ->orderByDesc('event_date')
            ->paginate(15)
            ->withQueryString();

        $categories = Event::whereNotNull('category')->distinct()->orderBy('category')->pluck('category');
        $years = Event::whereDate('event_date', '<', today())->orderByDesc('event_date')->pluck('event_date')
            ->map(fn ($date) => (int) substr((string) $date, 0, 4))->unique()->values();

        return view('admin.previous-events', compact('events', 'categories', 'years'));
    }

    public function show(Event $event, PreviousEventComparisonService $service)
    {
        $comparisons = $service->compare($event);
        $recommendations = $service->recommendations($event, $comparisons);

        return view('admin.event-comparison', compact('event', 'comparisons', 'recommendations'));
    }
}
