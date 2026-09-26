@extends('layouts.app')
@section('title', 'Management Reports')
@section('content')
@php
    $types = [
        'events' => ['Event Report', 'calendar-event'],
        'budget-event' => ['Budget & Event', 'cash-stack'],
        'proposals' => ['Proposals per Year', 'file-earmark-check'],
        'year-end-audit' => ['Year-End Audit', 'clipboard-data'],
        'liquidation' => ['Liquidation', 'receipt'],
    ];
@endphp
<div class="container-fluid py-4"><div class="row">
    <div class="col-lg-2 col-md-3">@include('layouts.partials.sidebar-admin')</div>
    <div class="col-lg-10 col-md-9">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
            <div><h4 class="fw-bold mb-1" style="color:var(--nu-blue)"><i class="bi bi-bar-chart me-2"></i>Management Reports</h4><p class="text-muted mb-0">Connected event, proposal, audit, budget, and liquidation records.</p></div>
            <a href="{{ route('admin.reports.export', ['type' => $type, 'year' => $year]) }}" class="btn btn-danger"><i class="bi bi-file-pdf me-1"></i>Export {{ $report['title'] }}</a>
        </div>
        <div class="row g-3 mb-4">
            @foreach($types as $key => [$label, $icon])
                <div class="col-xl col-md-4 col-6"><a href="{{ route('admin.reports', ['type' => $key, 'year' => $year]) }}" class="nu-card p-3 d-block text-decoration-none h-100 {{ $type === $key ? 'border border-primary' : '' }}"><i class="bi bi-{{ $icon }} fs-4 d-block mb-2" style="color:var(--nu-gold)"></i><strong style="color:var(--nu-blue)">{{ $label }}</strong></a></div>
            @endforeach
        </div>
        <div class="nu-card p-4 mb-4"><form method="GET" class="row align-items-end g-3"><input type="hidden" name="type" value="{{ $type }}"><div class="col-md-4"><label class="form-label fw-bold">Reporting year</label><select name="year" class="form-select">@foreach($years as $availableYear)<option value="{{ $availableYear }}" @selected($year === (int) $availableYear)>{{ $availableYear }}</option>@endforeach</select></div><div class="col-md-3"><button class="btn btn-nu-blue w-100">Apply year</button></div></form></div>
        <div class="row g-3 mb-4">@foreach($report['summary'] as $label => $value)<div class="col-md-3 col-6"><div class="nu-card p-3 h-100"><small class="text-muted d-block">{{ $label }}</small><strong class="fs-5" style="color:var(--nu-blue)">{{ $value }}</strong></div></div>@endforeach</div>
        <div class="nu-card"><div class="p-4 border-bottom"><h5 class="fw-bold mb-0">{{ $report['title'] }} — {{ $year }}</h5></div><div class="table-responsive"><table class="table nu-table align-middle mb-0"><thead><tr>@foreach($report['headers'] as $header)<th>{{ $header }}</th>@endforeach</tr></thead><tbody>@forelse($report['rows'] as $row)<tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($report['headers']) }}" class="text-center text-muted py-5">No records for {{ $year }}.</td></tr>@endforelse</tbody></table></div></div>
    </div>
</div></div>
@endsection
