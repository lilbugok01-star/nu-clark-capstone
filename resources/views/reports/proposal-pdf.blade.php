<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Proposal - {{ $proposal->proposal_number }}</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #333;
            line-height: 1.5;
            margin: 0;
            padding: 0;
            font-size: 11pt;
        }
        .header {
            border-bottom: 3px solid #003087; /* NU Blue */
            margin-bottom: 20px;
            padding-bottom: 10px;
        }
        .nu-blue { color: #003087; }
        .nu-gold { color: #FFB800; }
        .document-title {
            text-align: center;
            font-size: 18pt;
            font-weight: bold;
            color: #003087;
            text-transform: uppercase;
            margin: 20px 0 5px 0;
        }
        .proposal-no {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
            font-size: 10pt;
        }
        .event-info-table {
            width: 100%;
            margin-bottom: 30px;
            border-collapse: collapse;
        }
        .event-info-table td {
            padding: 5px;
            vertical-align: top;
        }
        .event-info-label {
            font-weight: bold;
            width: 120px;
            color: #555;
        }
        .section {
            margin-bottom: 20px;
        }
        .section-title {
            font-weight: bold;
            font-size: 12pt;
            color: #001d50;
            border-bottom: 1px solid #ccc;
            padding-bottom: 3px;
            margin-bottom: 10px;
        }
        .section-content {
            text-align: justify;
            white-space: pre-wrap;
        }
        .budget-amount {
            font-size: 14pt;
            font-weight: bold;
            color: #003087;
        }
        .signatures {
            margin-top: 50px;
            width: 100%;
        }
        .signature-box {
            width: 45%;
            display: inline-block;
            vertical-align: top;
        }
        .signature-line {
            border-bottom: 1px solid #000;
            width: 200px;
            margin-bottom: 5px;
            height: 40px;
            text-align: center;
            line-height: 65px;
        }
        .signature-name {
            font-weight: bold;
        }
        .signature-label {
            color: #666;
            font-size: 9pt;
        }
        .badge-status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 9pt;
            font-weight: bold;
            color: white;
            text-transform: uppercase;
        }
        .status-approved { background-color: #198754; }
        .status-submitted { background-color: #0d6efd; }
        .status-rejected { background-color: #dc3545; }
        .status-draft { background-color: #6c757d; }
        
        .budget-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 10pt;
        }
        .budget-table th, .budget-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .budget-table th {
            background-color: #f2f2f2;
            color: #003087;
        }
        .text-right { text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        <h2 style="margin:0; color:#003087;">National University - Clark</h2>
        <div style="color:#FFB800; font-weight:bold; font-size:10pt;">Event Management System</div>
    </div>

    <div class="document-title">Event Proposal</div>
    <div class="proposal-no">No. {{ $proposal->proposal_number }}</div>

    <table class="event-info-table">
        <tr>
            <td class="event-info-label">Event Title:</td>
            <td><strong>{{ $proposal->event->title ?? 'N/A' }}</strong></td>
            <td class="event-info-label">Status:</td>
            <td>
                <span class="badge-status status-{{ $proposal->status }}">
                    {{ ucfirst($proposal->status) }}
                </span>
            </td>
        </tr>
        <tr>
            <td class="event-info-label">Date & Time:</td>
            <td>{{ $proposal->event ? $proposal->event->event_date->format('F d, Y') . ' · ' . \Carbon\Carbon::parse($proposal->event->start_time)->format('h:i A') . '–' . \Carbon\Carbon::parse($proposal->event->end_time)->format('h:i A') : 'N/A' }}</td>
            <td class="event-info-label">Submitted:</td>
            <td>{{ $proposal->created_at->format('M d, Y') }}</td>
        </tr>
        <tr>
            <td class="event-info-label">Venue:</td>
            <td colspan="3">{{ $proposal->event->venue ?? 'N/A' }}</td>
        </tr>
    </table>

    <div class="section">
        <div class="section-title">1. Event Overview</div>
        <div class="section-content">{{ $proposal->event_overview }}</div>
    </div>

    <div class="section">
        <div class="section-title">2. Objectives</div>
        <div class="section-content">{{ $proposal->objectives }}</div>
    </div>

    @if($proposal->target_audience)
    <div class="section">
        <div class="section-title">3. Target Audience</div>
        <div class="section-content">{{ $proposal->target_audience }}</div>
    </div>
    @endif

    @if($proposal->venue_details || $proposal->schedule_details)
    <table style="width: 100%; margin-bottom: 20px;">
        <tr>
            @if($proposal->venue_details)
            <td style="width: 50%; padding-right: 10px; vertical-align: top;">
                <div class="section-title">Venue Details</div>
                <div class="section-content">{{ $proposal->venue_details }}</div>
            </td>
            @endif
            @if($proposal->schedule_details)
            <td style="width: 50%; padding-left: 10px; vertical-align: top;">
                <div class="section-title">Schedule Details</div>
                <div class="section-content">{{ $proposal->schedule_details }}</div>
            </td>
            @endif
        </tr>
    </table>
    @endif

    @if($proposal->requirements)
    <div class="section">
        <div class="section-title">Requirements / Logistics</div>
        <div class="section-content">{{ $proposal->requirements }}</div>
    </div>
    @endif

    @if($proposal->expected_outcomes)
    <div class="section">
        <div class="section-title">Expected Outcomes</div>
        <div class="section-content">{{ $proposal->expected_outcomes }}</div>
    </div>
    @endif

    <div class="section">
        <div class="section-title">Equipment Request Checklist</div>
        @forelse($proposal->event->equipmentRequests as $item)
            <div>☐ {{ $item->item_name }} — Qty {{ $item->quantity }}@if($item->purpose) ({{ $item->purpose }})@endif</div>
        @empty
            <div>No equipment requested.</div>
        @endforelse
    </div>

    <div class="section">
        <div class="section-title">Previous-Event Recommendations Applied</div>
        <div class="section-content">{{ $proposal->recommendations }}</div>
    </div>

    <div class="section" style="page-break-inside: avoid;">
        <div class="section-title">Financial Details</div>
        <div>Estimated Budget Total: <span class="budget-amount">Php {{ number_format($proposal->estimated_budget, 2) }}</span></div>
        
        @if(isset($budgetItems) && $budgetItems->count() > 0)
        <table class="budget-table">
            <thead>
                <tr>
                    <th>Item Category</th>
                    <th>Description</th>
                    <th class="text-right">Estimated Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($budgetItems as $item)
                <tr>
                    <td>{{ ucfirst($item->category) }}</td>
                    <td>{{ $item->description }}</td>
                    <td class="text-right">Php {{ number_format($item->estimated_amount, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>

    <div class="signatures" style="page-break-inside: avoid;">
        <div class="signature-box">
            <div class="signature-label">Prepared By:</div>
            <div class="signature-line">
                <span class="signature-name">{{ $proposal->preparedBy->full_name ?? 'N/A' }}</span>
            </div>
            <div class="signature-label">Date: {{ $proposal->created_at->format('M d, Y') }}</div>
        </div>

        <div class="signature-box" style="margin-left: 5%;">
            <div class="signature-label">Approved By:</div>
            <div class="signature-line">
                @if($proposal->status === 'approved')
                    <span class="signature-name" style="color: #198754;">{{ $proposal->approvedBy->full_name ?? 'Administrator' }}</span>
                @endif
            </div>
            <div class="signature-label">Date: {{ $proposal->approved_at ? \Carbon\Carbon::parse($proposal->approved_at)->format('M d, Y') : '________________' }}</div>
        </div>
    </div>

</body>
</html>
