<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Registration;
use App\Models\Attendance;
use App\Models\VenueReservation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Predictive Analytics Service
 * 
 * Provides statistical analysis and weighted-average forecasting for event attendance,
 * schedule optimization, and resource planning based on historical data.
 * NOTE: This relies purely on statistical models, not machine learning algorithms.
 */
class PredictiveAnalyticsService
{
    // Reuse historical aggregates within this service instance (one HTTP request).
    private ?array $historicalSummary = null;
    private ?array $categoryRates = null;
    private ?array $venueRates = null;
    private ?array $dayPatterns = null;

    /**
     * Forecasts expected attendance for an event using a weighted statistical approach.
     *
     * @param Event $event
     * @return array
     */
    public function predictAttendance(Event $event): array
    {
        // Define initial weights
        $weights = [
            'category' => 40,
            'venue' => 20,
            'system' => 20,
            'day_of_week' => 10,
            'momentum' => 10,
        ];
        
        $rates = [];
        $dataSources = [];
        $factors = [];

        // 1. System-wide Rate
        $systemSummary = $this->getHistoricalDataSummary();
        if ($systemSummary['total_completed_events'] > 0 && $systemSummary['overall_attendance_rate'] > 0) {
            $rates['system'] = $systemSummary['overall_attendance_rate'];
            $dataSources[] = 'System-wide historical data';
            $factors[] = [
                'name' => 'System-wide Rate',
                'value' => $rates['system'],
                'weight' => $weights['system'],
                'data_points' => $systemSummary['total_completed_events']
            ];
        }

        // 2. Category Historical Rate
        if (!empty($event->category)) {
            $categoryRates = $this->getCategoryAttendanceRates();
            if (isset($categoryRates[$event->category]) && $categoryRates[$event->category]['events'] > 0) {
                $rates['category'] = $categoryRates[$event->category]['attendance_rate'];
                $dataSources[] = 'Category historical data (' . $event->category . ')';
                $factors[] = [
                    'name' => 'Category Historical Rate',
                    'value' => $rates['category'],
                    'weight' => $weights['category'],
                    'data_points' => $categoryRates[$event->category]['events']
                ];
            }
        }

        // 3. Venue Historical Rate
        if (!empty($event->venue)) {
            $venueRates = $this->getVenueAttendanceRates();
            if (isset($venueRates[$event->venue]) && $venueRates[$event->venue]['events'] > 0) {
                $rates['venue'] = $venueRates[$event->venue]['attendance_rate'];
                $dataSources[] = 'Venue historical data (' . $event->venue . ')';
                $factors[] = [
                    'name' => 'Venue Historical Rate',
                    'value' => $rates['venue'],
                    'weight' => $weights['venue'],
                    'data_points' => $venueRates[$event->venue]['events']
                ];
            }
        }

        // 4. Day-of-week Pattern
        $eventDate = $event->event_date ? Carbon::parse($event->event_date) : null;
        if ($eventDate) {
            $dayName = $eventDate->format('l');
            $dayRates = $this->getDayOfWeekPatterns();
            if (isset($dayRates[$dayName]) && $dayRates[$dayName]['events'] > 0) {
                $rates['day_of_week'] = $dayRates[$dayName]['attendance_rate'];
                $dataSources[] = 'Day of week pattern (' . $dayName . ')';
                $factors[] = [
                    'name' => 'Day-of-week Pattern',
                    'value' => $rates['day_of_week'],
                    'weight' => $weights['day_of_week'],
                    'data_points' => $dayRates[$dayName]['events']
                ];
            }
        }

        // 5. Registration Momentum
        $capacity = $event->capacity > 0 ? $event->capacity : 0;
        $currentRegistrations = (int) ($event->active_registrations_count ?? $event->registeredCount());
        if ($capacity > 0) {
            $momentumRate = min(100, ($currentRegistrations / $capacity) * 100);
            // Convert momentum to a projected attendance rate modifier based on how early it is (simplified)
            $rates['momentum'] = $momentumRate;
            $dataSources[] = 'Current registration momentum';
            $factors[] = [
                'name' => 'Registration Momentum',
                'value' => $rates['momentum'],
                'weight' => $weights['momentum'],
                'data_points' => $currentRegistrations
            ];
        }

        // Redistribute weights for missing factors
        $missingWeights = 0;
        $activeWeightsCount = 0;
        foreach ($weights as $key => $weight) {
            if (!isset($rates[$key])) {
                $missingWeights += $weight;
            } else {
                $activeWeightsCount++;
            }
        }

        if ($activeWeightsCount > 0 && $missingWeights > 0) {
            $redistribution = $missingWeights / $activeWeightsCount;
            foreach ($rates as $key => $val) {
                $weights[$key] += $redistribution;
                // Update weight in factors array
                foreach ($factors as &$factor) {
                    if (str_contains(strtolower($factor['name']), str_replace('_', ' ', $key)) ||
                        (str_contains(strtolower($factor['name']), 'momentum') && $key == 'momentum')) {
                        $factor['weight'] = $weights[$key];
                    }
                }
            }
        }

        // Calculate weighted average
        $predictedRate = 0;
        if ($activeWeightsCount > 0) {
            foreach ($rates as $key => $rate) {
                $predictedRate += ($rate * ($weights[$key] / 100));
            }
            $confidence = $activeWeightsCount >= 4 ? 'high' : ($activeWeightsCount >= 2 ? 'medium' : 'low');
        } else {
            // No historical data
            $predictedRate = 70.0; // Default 70%
            $confidence = 'low';
            $dataSources[] = 'Default baseline (no historical data available)';
        }

        // Calculate counts
        $expectedTotalBase = $capacity > 0 ? $capacity : ($currentRegistrations > 0 ? $currentRegistrations * 1.5 : 100);
        $predictedCount = (int) round($expectedTotalBase * ($predictedRate / 100));
        
        // Ensure we don't predict less than confirmed registrations * a realistic floor
        if ($currentRegistrations > 0 && $predictedCount < $currentRegistrations * 0.5) {
            $predictedCount = (int) round($currentRegistrations * 0.8);
        }

        $capacityUtilization = $capacity > 0 ? min(100, round(($predictedCount / $capacity) * 100, 2)) : 0;

        return [
            'predicted_count' => $predictedCount,
            'predicted_rate' => round($predictedRate, 2),
            'venue_capacity' => $capacity,
            'capacity_utilization' => $capacityUtilization,
            'current_registrations' => $currentRegistrations,
            'confidence' => $confidence,
            'data_sources' => $dataSources,
            'factors' => $factors,
        ];
    }

    /**
     * Runs predictAttendance on all upcoming published events and returns sorted array.
     *
     * @return array
     */
    public function predictAllUpcoming(): array
    {
        $upcomingEvents = Event::where('event_date', '>=', Carbon::today())
            ->whereIn('status', ['published'])
            ->withCount(['registrations as active_registrations_count' => function ($q) {
                $q->where('registrations.status', '!=', 'cancelled');
            }])
            ->get();

        $predictions = [];
        foreach ($upcomingEvents as $event) {
            $prediction = $this->predictAttendance($event);
            $prediction['event_id'] = $event->id;
            $prediction['event_title'] = $event->title;
            $prediction['event_date'] = $event->event_date;
            $predictions[] = $prediction;
        }

        // Sort by event date ascending
        usort($predictions, function ($a, $b) {
            return strtotime($a['event_date']) - strtotime($b['event_date']);
        });

        return $predictions;
    }

    /**
     * Finds optimal time slots based on parameters.
     *
     * @param array $params [category, venue, capacity, date_from, date_to, duration_hours]
     * @return array
     */
    public function getScheduleRecommendations(array $params): array
    {
        $dateFrom = Carbon::parse($params['date_from']);
        $dateTo = Carbon::parse($params['date_to']);
        $durationHours = $params['duration_hours'] ?? 2;
        $venue = $params['venue'] ?? null;
        $capacity = $params['capacity'] ?? null;
        
        $recommendations = [];
        $dayPatterns = $this->getDayOfWeekPatterns();

        for ($date = clone $dateFrom; $date->lte($dateTo); $date->addDay()) {
            $dayName = $date->format('l');
            
            // Basic confidence score based on historical day performance
            $dayScore = 50; // Default
            if (isset($dayPatterns[$dayName]) && $dayPatterns[$dayName]['events'] > 0) {
                $dayScore = $dayPatterns[$dayName]['attendance_rate']; // Higher attendance rate = better day
            }

            // Morning Slot (09:00 to 09:00 + duration)
            $this->evaluateSlot($recommendations, $date, '09:00:00', $durationHours, $venue, $dayScore, $dayName);
            
            // Afternoon Slot (13:00 to 13:00 + duration)
            $this->evaluateSlot($recommendations, $date, '13:00:00', $durationHours, $venue, $dayScore, $dayName);
        }

        // Sort by confidence score descending
        usort($recommendations, function ($a, $b) {
            return $b['confidence_score'] <=> $a['confidence_score'];
        });

        return array_slice($recommendations, 0, 10);
    }

    private function evaluateSlot(&$recommendations, Carbon $date, string $startTime, int $durationHours, ?string $venue, float $baseScore, string $dayName)
    {
        $start = Carbon::parse($date->format('Y-m-d') . ' ' . $startTime);
        $end = (clone $start)->addHours($durationHours);

        $conflictCount = 0;
        $reasons = ["Historical performance rating for $dayName is " . round($baseScore, 1) . "%"];

        if ($venue) {
            // Check Event conflicts
            $eventConflicts = Event::where('venue', $venue)
                ->where('event_date', $date->format('Y-m-d'))
                ->whereIn('status', ['published'])
                ->where(function ($query) use ($start, $end) {
                    $query->where(function ($q) use ($start, $end) {
                        $q->where('start_time', '<', $end->format('H:i:s'))
                          ->where('end_time', '>', $start->format('H:i:s'));
                    });
                })->count();

            if ($eventConflicts > 0) {
                $conflictCount += $eventConflicts;
                $reasons[] = "$eventConflicts existing event(s) scheduled at this venue during this time.";
            }

            // Check Reservation conflicts
            $reservationConflicts = VenueReservation::where('venue_name', $venue)
                ->where('reserved_date', $date->format('Y-m-d'))
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->where(function ($query) use ($start, $end) {
                    $query->where(function ($q) use ($start, $end) {
                        $q->where('start_time', '<', $end->format('H:i:s'))
                          ->where('end_time', '>', $start->format('H:i:s'));
                    });
                })->count();
                
            if ($reservationConflicts > 0) {
                $conflictCount += $reservationConflicts;
                $reasons[] = "$reservationConflicts venue reservation(s) during this time.";
            }
        }

        // Penalize score for conflicts
        $confidenceScore = $baseScore;
        if ($conflictCount > 0) {
            $confidenceScore = max(0, $confidenceScore - ($conflictCount * 30)); // -30 points per conflict
        } else {
            $reasons[] = "Time slot is clear of known venue conflicts.";
        }

        if ($confidenceScore > 0) {
            $recommendations[] = [
                'date' => $date->format('Y-m-d'),
                'day_name' => $dayName,
                'suggested_start' => $start->format('H:i'),
                'suggested_end' => $end->format('H:i'),
                'conflict_count' => $conflictCount,
                'confidence_score' => round($confidenceScore, 2),
                'reasons' => $reasons
            ];
        }
    }

    /**
     * Calculates resource requirements based on predicted attendance.
     *
     * @param Event $event
     * @param int|null $predictedAttendance
     * @return array
     */
    public function getResourcePlan(Event $event, ?int $predictedAttendance = null): array
    {
        if ($predictedAttendance === null) {
            $prediction = $this->predictAttendance($event);
            $predictedAttendance = $prediction['predicted_count'];
        }

        $capacity = $event->capacity ?? 0;
        $warnings = [];

        if ($capacity > 0 && $predictedAttendance > $capacity) {
            $warnings[] = 'Predicted attendance (' . $predictedAttendance . ') exceeds venue capacity (' . $capacity . ')';
        }

        $resources = [
            [
                'name' => 'Seats',
                'buffer_pct' => 5,
                'notes' => 'Basic seating',
                'recommended_quantity' => (int) ceil($predictedAttendance * 1.05)
            ],
            [
                'name' => 'Food Servings',
                'buffer_pct' => 10,
                'notes' => 'Catering preparation',
                'recommended_quantity' => (int) ceil($predictedAttendance * 1.10)
            ],
            [
                'name' => 'Materials/Kits',
                'buffer_pct' => 5,
                'notes' => 'Handouts or event kits',
                'recommended_quantity' => (int) ceil($predictedAttendance * 1.05)
            ],
            [
                'name' => 'Chairs',
                'buffer_pct' => 10,
                'notes' => 'Total chairs (including spares)',
                'recommended_quantity' => (int) ceil($predictedAttendance * 1.10)
            ],
            [
                'name' => 'Registration Forms',
                'buffer_pct' => 15,
                'notes' => 'Physical forms for walk-ins/backups',
                'recommended_quantity' => (int) ceil($predictedAttendance * 1.15)
            ],
            [
                'name' => 'Water Bottles',
                'buffer_pct' => 20,
                'notes' => 'Hydration provision',
                'recommended_quantity' => (int) ceil($predictedAttendance * 1.20)
            ],
        ];

        return [
            'predicted_attendance' => $predictedAttendance,
            'venue_capacity' => $capacity,
            'resources' => $resources,
            'warnings' => $warnings
        ];
    }

    /**
     * Retrieves overall summary of historical attendance data.
     *
     * @return array
     */
    public function getHistoricalDataSummary(): array
    {
        if ($this->historicalSummary !== null) {
            return $this->historicalSummary;
        }

        $completedEvents = Event::where('event_date', '<', Carbon::today())
            ->whereIn('status', ['published', 'completed']);

        $totalCompleted = $completedEvents->count();
        $earliestDate = $completedEvents->min('event_date');
        $latestDate = $completedEvents->max('event_date');

        // Total confirmed registrations for completed events
        $eventIds = $completedEvents->pluck('id')->toArray();
        $totalRegistrations = Registration::whereIn('event_id', $eventIds)
            ->where('status', 'confirmed')
            ->count();

        // Total verified attendances (joined via registration to completed events)
        $totalVerifiedAttendances = Attendance::where('status', 'verified')
            ->whereHas('registration', function ($q) use ($eventIds) {
                $q->whereIn('event_id', $eventIds)->where('status', 'confirmed');
            })->count();

        $overallRate = $totalRegistrations > 0 ? ($totalVerifiedAttendances / $totalRegistrations) * 100 : 0;

        $categories = $completedEvents->clone()->whereNotNull('category')->groupBy('category')->select('category', DB::raw('count(*) as count'))->pluck('count', 'category')->toArray();
        $venues = $completedEvents->clone()->whereNotNull('venue')->groupBy('venue')->select('venue', DB::raw('count(*) as count'))->pluck('count', 'venue')->toArray();

        // Data quality assessment
        $quality = 'insufficient';
        $qualityNotes = 'Not enough historical data to make reliable predictions.';
        if ($totalCompleted >= 20) {
            $quality = 'sufficient';
            $qualityNotes = 'Good amount of historical data available across various events.';
        } elseif ($totalCompleted >= 5) {
            $quality = 'limited';
            $qualityNotes = 'Limited data available. Predictions may have higher variance.';
        }

        return $this->historicalSummary = [
            'total_completed_events' => $totalCompleted,
            'total_registrations' => $totalRegistrations,
            'total_verified_attendances' => $totalVerifiedAttendances,
            'overall_attendance_rate' => round($overallRate, 2),
            'categories_with_data' => $categories,
            'venues_with_data' => $venues,
            'earliest_event_date' => $earliestDate,
            'latest_event_date' => $latestDate,
            'data_quality' => $quality,
            'data_quality_notes' => $qualityNotes,
        ];
    }

    /**
     * Calculates historical attendance rate grouped by category.
     *
     * @return array
     */
    public function getCategoryAttendanceRates(): array
    {
        return $this->categoryRates ??= $this->calculateRatesGroupedBy('category');
    }

    /**
     * Calculates historical attendance rate grouped by venue.
     *
     * @return array
     */
    public function getVenueAttendanceRates(): array
    {
        return $this->venueRates ??= $this->calculateRatesGroupedBy('venue');
    }

    /**
     * Calculates historical attendance rate grouped by day of the week.
     *
     * @return array
     */
    public function getDayOfWeekPatterns(): array
    {
        if ($this->dayPatterns !== null) {
            return $this->dayPatterns;
        }

        $events = Event::where('event_date', '<', Carbon::today())
            ->whereIn('status', ['published', 'completed'])
            ->select(['id', 'event_date'])
            ->withCount(['registrations' => function ($q) {
                $q->where('registrations.status', 'confirmed');
            }, 'attendances' => function ($q) {
                $q->where('attendances.status', 'verified');
            }])
            ->get();

        $grouped = [];

        foreach ($events as $event) {
            if (!$event->event_date) continue;
            
            $dayName = Carbon::parse($event->event_date)->format('l');
            
            if (!isset($grouped[$dayName])) {
                $grouped[$dayName] = [
                    'events' => 0,
                    'total_regs' => 0,
                    'total_atts' => 0,
                ];
            }

            $grouped[$dayName]['events']++;
            $grouped[$dayName]['total_regs'] += $event->registrations_count;
            $grouped[$dayName]['total_atts'] += $event->attendances_count;
        }

        return $this->dayPatterns = $this->formatGroupedRates($grouped);
    }

    /**
     * Helper to group rates by a specific column.
     *
     * @param string $column
     * @return array
     */
    private function calculateRatesGroupedBy(string $column): array
    {
        $events = Event::where('event_date', '<', Carbon::today())
            ->whereIn('status', ['published', 'completed'])
            ->whereNotNull($column)
            ->select(['id', $column])
            ->withCount(['registrations' => function ($q) {
                $q->where('registrations.status', 'confirmed');
            }, 'attendances' => function ($q) {
                $q->where('attendances.status', 'verified');
            }])
            ->get();

        $grouped = [];

        foreach ($events as $event) {
            $key = $event->$column;
            
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'events' => 0,
                    'total_regs' => 0,
                    'total_atts' => 0,
                ];
            }

            $grouped[$key]['events']++;
            $grouped[$key]['total_regs'] += $event->registrations_count;
            $grouped[$key]['total_atts'] += $event->attendances_count;
        }

        return $this->formatGroupedRates($grouped);
    }

    /**
     * Formats the raw grouped data into the final expected structure.
     *
     * @param array $grouped
     * @return array
     */
    private function formatGroupedRates(array $grouped): array
    {
        $result = [];
        foreach ($grouped as $key => $data) {
            $avgReg = $data['events'] > 0 ? $data['total_regs'] / $data['events'] : 0;
            $avgAtt = $data['events'] > 0 ? $data['total_atts'] / $data['events'] : 0;
            $rate = $data['total_regs'] > 0 ? ($data['total_atts'] / $data['total_regs']) * 100 : 0;

            $result[$key] = [
                'events' => $data['events'],
                'avg_registrations' => round($avgReg, 2),
                'avg_attendances' => round($avgAtt, 2),
                'attendance_rate' => round($rate, 2),
            ];
        }

        return $result;
    }
}
