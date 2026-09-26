<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PreviousEventComparisonService
{
    /**
     * Return real previous events that resemble the selected event. No forecast or
     * generated attendance value is used; every figure comes from recorded data.
     */
    public function compare(Event $event, int $limit = 5): Collection
    {
        $event->loadMissing('equipmentRequests');

        return Event::query()
            ->whereKeyNot($event->id)
            ->whereDate('event_date', '<', $event->event_date?->toDateString() ?? today()->toDateString())
            ->whereIn('status', ['published', 'completed'])
            ->with(['budgetItems', 'payments', 'equipmentRequests'])
            ->withCount([
                'registrations as registrations_count' => fn ($query) => $query->where('status', '!=', 'cancelled'),
                'registrations as attended_count' => fn ($query) => $query->whereHas(
                    'attendance', fn ($attendance) => $attendance->where('status', 'verified')
                ),
            ])
            ->get()
            ->map(function (Event $previous) use ($event) {
                [$score, $reasons] = $this->similarity($event, $previous);
                $previous->comparison_score = $score;
                $previous->comparison_reasons = $reasons;
                $previous->attendance_rate = $previous->registrations_count > 0
                    ? round(($previous->attended_count / $previous->registrations_count) * 100, 1)
                    : 0;
                $previous->actual_expenses = (float) $previous->payments
                    ->where('payment_type', 'expense')->sum('amount');

                return $previous;
            })
            ->filter(fn (Event $previous) => $previous->comparison_score > 0)
            ->sortByDesc('comparison_score')
            ->take($limit)
            ->values();
    }

    public function recommendations(Event $event, ?Collection $comparisons = null): array
    {
        $comparisons ??= $this->compare($event);

        if ($comparisons->isEmpty()) {
            return ['No sufficiently similar previous event is recorded yet. Use the proposal objectives, confirmed capacity, and itemized quotations as the planning basis.'];
        }

        $recommendations = [];
        $eventsWithRegistrations = $comparisons->where('registrations_count', '>', 0);

        if ($eventsWithRegistrations->isNotEmpty()) {
            $averageRegistrations = (int) round($eventsWithRegistrations->avg('registrations_count'));
            $averageAttendance = (int) round($eventsWithRegistrations->avg('attended_count'));
            $recommendations[] = "Plan for approximately {$averageRegistrations} registrations and {$averageAttendance} actual attendees, based on the recorded averages of similar previous events.";
        }

        $expenses = $comparisons->pluck('actual_expenses')->filter(fn ($amount) => $amount > 0);
        if ($expenses->isNotEmpty()) {
            $recommendations[] = 'Use PHP ' . number_format((float) $expenses->avg(), 2)
                . ' as a historical spending reference, then support the proposed amount with current quotations.';
        }

        $equipment = $comparisons->flatMap->equipmentRequests
            ->groupBy(fn ($item) => Str::lower($item->item_name))
            ->map(fn ($items) => [
                'name' => $items->first()->item_name,
                'quantity' => (int) round($items->avg('quantity')),
                'uses' => $items->count(),
            ])
            ->sortByDesc('uses')
            ->take(5);

        if ($equipment->isNotEmpty()) {
            $list = $equipment->map(fn ($item) => "{$item['name']} ({$item['quantity']})")->implode(', ');
            $recommendations[] = "Review these commonly requested equipment items: {$list}. Include only items needed for this event.";
        }

        $best = $comparisons->first();
        $recommendations[] = "Use \"{$best->title}\" ({$best->event_date->format('Y')}) as the closest documentary reference and explain any major changes in scope, venue, budget, or equipment.";

        return $recommendations;
    }

    private function similarity(Event $event, Event $previous): array
    {
        $score = 0;
        $reasons = [];

        if ($event->category && Str::lower($event->category) === Str::lower((string) $previous->category)) {
            $score += 40;
            $reasons[] = 'same category';
        }

        if ($event->venue && Str::lower($event->venue) === Str::lower((string) $previous->venue)) {
            $score += 15;
            $reasons[] = 'same venue';
        }

        $currentWords = $this->meaningfulWords($event->title . ' ' . $event->description);
        $previousWords = $this->meaningfulWords($previous->title . ' ' . $previous->description);
        $sharedWords = array_values(array_intersect($currentWords, $previousWords));
        if ($sharedWords !== []) {
            $score += min(45, count($sharedWords) * 9);
            $reasons[] = 'matching description: ' . implode(', ', array_slice($sharedWords, 0, 4));
        }

        return [min(100, $score), $reasons];
    }

    private function meaningfulWords(string $text): array
    {
        $stopWords = ['about', 'after', 'again', 'also', 'and', 'are', 'event', 'for', 'from', 'into', 'our', 'the', 'this', 'with', 'will', 'your'];
        $words = preg_split('/[^a-z0-9]+/i', Str::lower($text), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter(
            $words ?: [],
            fn ($word) => strlen($word) >= 4 && !in_array($word, $stopWords, true)
        )));
    }
}
