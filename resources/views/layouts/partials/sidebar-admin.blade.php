<div class="dashboard-sidebar rounded-xl mb-4">
    <div class="text-white-50 small text-uppercase fw-semibold mb-3 ps-2" style="letter-spacing:1px">Admin Panel</div>
    <a href="{{ route('admin.dashboard') }}" class="sidebar-link @if(request()->routeIs('admin.dashboard')) active @endif">
        <i class="bi bi-speedometer2"></i> Dashboard
    </a>
    <a href="{{ route('admin.events') }}" class="sidebar-link @if(request()->routeIs('admin.events*')) active @endif">
        <i class="bi bi-calendar-event"></i> Manage Events
    </a>
    <a href="{{ route('admin.users') }}" class="sidebar-link @if(request()->routeIs('admin.users*')) active @endif">
        <i class="bi bi-people"></i> Users
    </a>
    <a href="{{ route('admin.courses') }}" class="sidebar-link @if(request()->routeIs('admin.courses*')) active @endif">
        <i class="bi bi-book"></i> Courses
    </a>
    <a href="{{ route('admin.reports') }}" class="sidebar-link @if(request()->routeIs('admin.reports*')) active @endif">
        <i class="bi bi-bar-chart"></i> Reports
    </a>
    <a href="{{ route('admin.analytics') }}" class="sidebar-link @if(request()->routeIs('admin.analytics*')) active @endif">
        <i class="bi bi-graph-up-arrow"></i> Analytics
    </a>
    <a href="{{ route('admin.previous-events.index') }}" class="sidebar-link @if(request()->routeIs('admin.previous-events*')) active @endif">
        <i class="bi bi-clock-history"></i> Previous Events
    </a>
    <a href="{{ route('admin.financial') }}" class="sidebar-link @if(request()->routeIs('admin.financial*') || request()->routeIs('admin.event.budget*') || request()->routeIs('admin.event.payments*')) active @endif">
        <i class="bi bi-cash-stack"></i> Financial
    </a>
    <a href="{{ route('admin.venues') }}" class="sidebar-link @if(request()->routeIs('admin.venues*')) active @endif">
        <i class="bi bi-building"></i> Venues
    </a>
    <a href="{{ route('admin.file-hunting') }}" class="sidebar-link @if(request()->routeIs('admin.file-hunting*')) active @endif">
        <i class="bi bi-file-earmark-check"></i> File Hunting
    </a>
    <a href="{{ route('admin.audit-logs') }}" class="sidebar-link @if(request()->routeIs('admin.audit-logs*')) active @endif">
        <i class="bi bi-journal-text"></i> Audit Logs
    </a>
    <a href="{{ route('admin.notifications') }}" class="sidebar-link @if(request()->routeIs('admin.notifications*')) active @endif">
        <i class="bi bi-megaphone"></i> Notifications
    </a>
    <a href="{{ route('admin.import') }}" class="sidebar-link @if(request()->routeIs('admin.import*')) active @endif">
        <i class="bi bi-upload"></i> Import Students
    </a>
    <a href="{{ route('events') }}" class="sidebar-link">
        <i class="bi bi-calendar3"></i> View Events
    </a>
    <hr style="border-color:rgba(255,255,255,0.1)">
    <button type="button" class="sidebar-link w-100 border-0 bg-transparent text-start" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal">
        <i class="bi bi-box-arrow-right"></i> Logout
    </button>
</div>
