<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventBudget;
use App\Models\EventPayment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Schema;

class FinancialController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            'auth',
            new Middleware(function ($request, $next) {
                if (!Auth::check() || !in_array(Auth::user()->role, ['admin', 'organizer'])) {
                    abort(403, 'Access denied.');
                }
                return $next($request);
            }),
        ];
    }

    // Financial Dashboard - overview of all events' financial data
    public function dashboard(Request $request)
    {
        $hasBudgetTable = Schema::hasTable('event_budgets');
        $hasPaymentTable = Schema::hasTable('event_payments');

        $query = Event::query();

        if ($hasBudgetTable && $hasPaymentTable) {
            $query->with(['budgets', 'payments']);
        }

        if (Auth::user()->role === 'organizer') {
            $query->where('organizer_id', Auth::id());
        }

        // Compute grand totals from ALL events (before pagination)
        $allEvents = $query->clone()->get();

        $totalEstimatedBudget = 0;
        $totalActualSpent = 0;
        $totalIncome = 0;
        $totalExpenses = 0;
        $eventsWithBudgetCount = 0;

        foreach ($allEvents as $ev) {
            $eventEst = ($hasBudgetTable && $ev->relationLoaded('budgets')) ? (float) $ev->budgets->sum('estimated_amount') : 0;
            $eventAct = ($hasBudgetTable && $ev->relationLoaded('budgets')) ? (float) $ev->budgets->sum('actual_amount') : 0;
            $eventInc = ($hasPaymentTable && $ev->relationLoaded('payments')) ? (float) $ev->payments->where('payment_type', 'income')->sum('amount') : 0;
            $eventExp = ($hasPaymentTable && $ev->relationLoaded('payments')) ? (float) $ev->payments->where('payment_type', 'expense')->sum('amount') : 0;

            if ($eventEst > 0 || $eventAct > 0) {
                $eventsWithBudgetCount++;
            }

            $totalEstimatedBudget += $eventEst;
            $totalActualSpent += $eventAct;
            $totalIncome += $eventInc;
            $totalExpenses += $eventExp;
        }

        $netProfitLoss = $totalIncome - $totalExpenses;

        $stats = [
            'total_estimated_budget'   => $totalEstimatedBudget,
            'total_actual_spent'       => $totalActualSpent,
            'total_income'             => $totalIncome,
            'total_expenses'           => $totalExpenses,
            'net_profit_loss'          => $netProfitLoss,
            'total_events_with_budget' => $eventsWithBudgetCount,
        ];

        // Now paginate for display
        $events = $query->orderByDesc('event_date')->paginate(15);

        // Attach per-event computed properties for the table
        foreach ($events as $event) {
            $event->total_estimated_budget = ($hasBudgetTable && $event->relationLoaded('budgets')) ? (float) $event->budgets->sum('estimated_amount') : 0;
            $event->total_actual_spent = ($hasBudgetTable && $event->relationLoaded('budgets')) ? (float) $event->budgets->sum('actual_amount') : 0;
            $event->total_income = ($hasPaymentTable && $event->relationLoaded('payments')) ? (float) $event->payments->where('payment_type', 'income')->sum('amount') : 0;
            $event->total_expenses = ($hasPaymentTable && $event->relationLoaded('payments')) ? (float) $event->payments->where('payment_type', 'expense')->sum('amount') : 0;
        }

        return view('admin.financial-dashboard', compact(
            'events', 
            'stats',
            'totalEstimatedBudget', 
            'totalActualSpent', 
            'totalIncome', 
            'totalExpenses', 
            'netProfitLoss'
        ));
    }
    
    // Event Budget Management - view and manage budget for specific event
    public function eventBudget($eventId)
    {
        $hasBudgetTable = Schema::hasTable('event_budgets');

        $query = Event::query();
        if ($hasBudgetTable) {
            $query->with(['budgets' => function($q) {
                $q->orderBy('category', 'asc');
            }]);
        }

        if (Auth::user()->role === 'organizer') {
            $query->where('organizer_id', Auth::id());
        }

        $event = $query->findOrFail($eventId);
        $budgetItems = $hasBudgetTable ? $event->budgets : collect();

        $estimated = (float) $budgetItems->sum('estimated_amount');
        $actual = (float) $budgetItems->sum('actual_amount');
        $variance = $estimated - $actual;

        $totals = [
            'estimated'       => $estimated,
            'actual'          => $actual,
            'variance'        => $variance,
            'total_estimated' => $estimated,
            'total_actual'    => $actual,
        ];

        $categories = EventBudget::budgetCategories();

        return view('admin.event-budget', compact('event', 'budgetItems', 'totals', 'categories'));
    }
    
    // Store a budget line item
    public function storeBudgetItem(Request $request, $eventId)
    {
        $event = Event::findOrFail($eventId);
        if (Auth::user()->role === 'organizer' && $event->organizer_id !== Auth::id()) {
            abort(403, 'Access denied.');
        }

        $validated = $request->validate([
            'category'         => 'required|string|max:100',
            'description'      => 'required|string|max:255',
            'estimated_amount' => 'required|numeric|decimal:0,2|min:0|max:9999999999.99',
            'actual_amount'    => 'nullable|numeric|decimal:0,2|min:0|max:9999999999.99',
            'status'           => 'required|in:planned,approved,spent,cancelled'
        ]);

        $validated['event_id'] = $eventId;
        
        $budgetItem = EventBudget::create($validated);
        
        User::log('created_budget_item', $budgetItem, null, $validated);

        return back()->with('success', 'Budget item added successfully.');
    }
    
    // Update a budget line item
    public function updateBudgetItem(Request $request, $id)
    {
        $budgetItem = EventBudget::findOrFail($id);
        $event = $budgetItem->event;
        
        if (Auth::user()->role === 'organizer' && $event->organizer_id !== Auth::id()) {
            abort(403, 'Access denied.');
        }

        $validated = $request->validate([
            'category'         => 'required|string|max:100',
            'description'      => 'required|string|max:255',
            'estimated_amount' => 'required|numeric|decimal:0,2|min:0|max:9999999999.99',
            'actual_amount'    => 'nullable|numeric|decimal:0,2|min:0|max:9999999999.99',
            'status'           => 'required|in:planned,approved,spent,cancelled'
        ]);

        $old = $budgetItem->toArray();
        $budgetItem->update($validated);
        
        User::log('updated_budget_item', $budgetItem, $old, $validated);

        return back()->with('success', 'Budget item updated successfully.');
    }
    
    // Delete a budget line item
    public function deleteBudgetItem($id)
    {
        $budgetItem = EventBudget::findOrFail($id);
        $event = $budgetItem->event;
        
        if (Auth::user()->role === 'organizer' && $event->organizer_id !== Auth::id()) {
            abort(403, 'Access denied.');
        }

        $old = $budgetItem->toArray();
        $budgetItem->delete();
        
        User::log('deleted_budget_item', $budgetItem, $old, null);

        return back()->with('success', 'Budget item deleted successfully.');
    }
    
    // Event Payments - view and manage payments for specific event
    public function eventPayments($eventId)
    {
        $hasPaymentTable = Schema::hasTable('event_payments');

        $query = Event::query();
        if ($hasPaymentTable) {
            $query->with(['payments' => function($q) {
                $q->orderBy('payment_date', 'desc');
            }]);
        }

        if (Auth::user()->role === 'organizer') {
            $query->where('organizer_id', Auth::id());
        }

        $event = $query->findOrFail($eventId);
        $payments = $hasPaymentTable ? $event->payments : collect();

        $income = (float) $payments->where('payment_type', 'income')->sum('amount');
        $expense = (float) $payments->where('payment_type', 'expense')->sum('amount');

        $totals = [
            'income'        => $income,
            'expense'       => $expense,
            'net'           => $income - $expense,
            'total_income'  => $income,
            'total_expense' => $expense,
        ];

        $paymentMethods = EventPayment::paymentMethods();

        return view('admin.event-payments', compact('event', 'payments', 'totals', 'paymentMethods'));
    }
    
    // Store a payment record
    public function storePayment(Request $request, $eventId)
    {
        $event = Event::findOrFail($eventId);
        if (Auth::user()->role === 'organizer' && $event->organizer_id !== Auth::id()) {
            abort(403, 'Access denied.');
        }

        $validated = $request->validate([
            'payment_type'     => 'required|in:income,expense',
            'amount'           => 'required|numeric|decimal:0,2|min:0.01|max:9999999999.99',
            'description'      => 'required|string|max:255',
            'payment_method'   => 'nullable|string|max:100',
            'payment_date'     => 'required|date',
            'receipt'          => 'nullable|file|mimes:jpg,png,pdf|max:5120',
            'reference_number' => 'nullable|string|max:100',
            'notes'            => 'nullable|string'
        ]);

        $validated['event_id'] = $eventId;
        $validated['recorded_by'] = Auth::id();

        if ($request->hasFile('receipt')) {
            $path = $request->file('receipt')->store('receipts', 'local');
            abort_unless($path, 503, 'Receipt storage is unavailable. Please try again.');
            $validated['receipt_path'] = $path;
        }

        $payment = EventPayment::create($validated);
        
        User::log('created_payment', $payment, null, $validated);

        return back()->with('success', 'Payment recorded successfully.');
    }
    
    // Delete a payment record
    public function deletePayment($id)
    {
        $payment = EventPayment::findOrFail($id);
        $event = $payment->event;
        
        if (Auth::user()->role === 'organizer' && $event->organizer_id !== Auth::id()) {
            abort(403, 'Access denied.');
        }

        $old = $payment->toArray();
        $payment->delete();
        
        User::log('deleted_payment', $payment, $old, null);

        return back()->with('success', 'Payment deleted successfully.');
    }
    
    // Export financial report PDF for an event
    public function exportPdf($eventId)
    {
        $query = Event::with(['budgets', 'payments']);
        
        if (Auth::user()->role === 'organizer') {
            $query->where('organizer_id', Auth::id());
        }

        $event = $query->findOrFail($eventId);

        $budgetItems = $event->budgets;
        $payments = $event->payments;

        $totals = [
            'estimated_budget' => (float) $budgetItems->sum('estimated_amount'),
            'actual_spent'     => (float) $budgetItems->sum('actual_amount'),
            'total_estimated'  => (float) $budgetItems->sum('estimated_amount'),
            'total_actual'     => (float) $budgetItems->sum('actual_amount'),
            'income'           => (float) $payments->where('payment_type', 'income')->sum('amount'),
            'expense'          => (float) $payments->where('payment_type', 'expense')->sum('amount'),
            'total_income'     => (float) $payments->where('payment_type', 'income')->sum('amount'),
            'total_expense'    => (float) $payments->where('payment_type', 'expense')->sum('amount'),
        ];
        $totals['net'] = $totals['income'] - $totals['expense'];

        $pdf = Pdf::loadView('reports.financial-pdf', compact('event', 'totals', 'budgetItems', 'payments'));
        
        User::log('exported_financial_pdf', $event, null, null);

        return $pdf->download("financial-report-{$eventId}.pdf");
    }
}
