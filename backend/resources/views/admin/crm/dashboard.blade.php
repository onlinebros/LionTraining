@extends('layouts.admin')

@section('title', 'CRM Dashboard')
@section('page-title', 'CRM Dashboard')

@section('breadcrumb')
    <li class="breadcrumb-item active">CRM</li>
@endsection

@section('content')

{{-- ── Stat cards ── --}}
<div class="row g-3 mb-4">
    <div class="col-xl-2 col-sm-4 col-6">
        <div class="card card-hover-effect h-100">
            <div class="card-body text-center py-3">
                <i data-feather="users" class="text-primary mb-2" style="width:28px;height:28px;"></i>
                <h4 class="mb-0 fw-bold">{{ number_format($stats['total_contacts']) }}</h4>
                <p class="f-light mb-0 small">Total Contacts</p>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-sm-4 col-6">
        <div class="card card-hover-effect h-100">
            <div class="card-body text-center py-3">
                <i data-feather="user-plus" class="text-info mb-2" style="width:28px;height:28px;"></i>
                <h4 class="mb-0 fw-bold">{{ number_format($stats['new_leads']) }}</h4>
                <p class="f-light mb-0 small">New Leads</p>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-sm-4 col-6">
        <div class="card card-hover-effect h-100">
            <div class="card-body text-center py-3">
                <i data-feather="trending-up" class="text-warning mb-2" style="width:28px;height:28px;"></i>
                <h4 class="mb-0 fw-bold">{{ number_format($stats['hot_leads']) }}</h4>
                <p class="f-light mb-0 small">Hot Leads</p>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-sm-4 col-6">
        <div class="card card-hover-effect h-100">
            <div class="card-body text-center py-3">
                <i data-feather="check-circle" class="text-success mb-2" style="width:28px;height:28px;"></i>
                <h4 class="mb-0 fw-bold">{{ number_format($stats['customers']) }}</h4>
                <p class="f-light mb-0 small">Customers</p>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-sm-4 col-6">
        <div class="card card-hover-effect h-100">
            <div class="card-body text-center py-3">
                <i data-feather="clock" class="text-warning mb-2" style="width:28px;height:28px;"></i>
                <h4 class="mb-0 fw-bold">{{ number_format($stats['followups_today']) }}</h4>
                <p class="f-light mb-0 small">Due Today</p>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-sm-4 col-6">
        <div class="card card-hover-effect h-100">
            <div class="card-body text-center py-3">
                <i data-feather="alert-circle" class="text-danger mb-2" style="width:28px;height:28px;"></i>
                <h4 class="mb-0 fw-bold">{{ number_format($stats['overdue']) }}</h4>
                <p class="f-light mb-0 small">Overdue</p>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">

    {{-- ── Follow-ups today ── --}}
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i data-feather="clock" class="me-1" style="width:16px;height:16px;"></i> Follow-ups Due Today</h5>
                <span class="badge badge-light-warning">{{ $followupsToday->count() }}</span>
            </div>
            <div class="card-body p-0">
                @forelse($followupsToday as $fu)
                    <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom">
                        <div class="flex-shrink-0">
                            <span class="badge badge-light-{{ \App\Models\CrmFollowup::$priorities[$fu->priority]['color'] ?? 'secondary' }}">
                                {{ ucfirst($fu->priority) }}
                            </span>
                        </div>
                        <div class="flex-grow-1 min-w-0">
                            <a href="{{ route('admin.crm.contacts.show', $fu->contact) }}" class="fw-semibold text-dark d-block text-truncate">
                                {{ $fu->title }}
                            </a>
                            <small class="f-light">{{ $fu->contact->full_name }} — {{ $fu->due_at->format('g:i A') }}</small>
                        </div>
                        <div class="flex-shrink-0 text-muted small">{{ $fu->assignee?->name ?? '—' }}</div>
                    </div>
                @empty
                    <p class="text-center f-light py-4 mb-0">No follow-ups due today.</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- ── Overdue follow-ups ── --}}
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i data-feather="alert-circle" class="me-1 text-danger" style="width:16px;height:16px;"></i> Overdue Follow-ups</h5>
                <span class="badge badge-light-danger">{{ $overdueFollowups->count() }}</span>
            </div>
            <div class="card-body p-0">
                @forelse($overdueFollowups as $fu)
                    <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom">
                        <div class="flex-shrink-0 text-danger small fw-semibold">
                            {{ $fu->due_at->diffForHumans() }}
                        </div>
                        <div class="flex-grow-1 min-w-0">
                            <a href="{{ route('admin.crm.contacts.show', $fu->contact) }}" class="fw-semibold text-dark d-block text-truncate">
                                {{ $fu->title }}
                            </a>
                            <small class="f-light">{{ $fu->contact->full_name }}</small>
                        </div>
                        <div class="flex-shrink-0 text-muted small">{{ $fu->assignee?->name ?? '—' }}</div>
                    </div>
                @empty
                    <p class="text-center f-light py-4 mb-0">No overdue follow-ups.</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- ── Recent contacts ── --}}
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Recent Contacts</h5>
                <a href="{{ route('admin.crm.contacts.index') }}" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Owner</th>
                                <th>Added</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($recentContacts as $c)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.crm.contacts.show', $c) }}" class="fw-semibold">
                                            {{ $c->full_name }}
                                        </a>
                                        @if($c->email)
                                            <div class="small f-light">{{ $c->email }}</div>
                                        @endif
                                    </td>
                                    <td><span class="badge badge-light-secondary">{{ \App\Models\CrmContact::$contactTypes[$c->contact_type] ?? $c->contact_type }}</span></td>
                                    <td><span class="badge badge-light-{{ $c->status_color }}">{{ $c->status_label }}</span></td>
                                    <td class="text-muted small">{{ $c->owner?->name ?? '—' }}</td>
                                    <td class="text-muted small">{{ $c->created_at->diffForHumans() }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center f-light py-3">No contacts yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Top affiliates ── --}}
    <div class="col-xl-4">
        <div class="card">
            <div class="card-header"><h5 class="mb-0">Top Affiliates by Contacts</h5></div>
            <div class="card-body p-0">
                @forelse($topAffiliates as $row)
                    <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
                        <span class="fw-semibold">{{ $row->owner?->name ?? 'Unknown' }}</span>
                        <span class="badge badge-light-primary">{{ $row->contact_count }}</span>
                    </div>
                @empty
                    <p class="text-center f-light py-4 mb-0">No data yet.</p>
                @endforelse
            </div>
        </div>

        {{-- Pipeline status breakdown --}}
        <div class="card mt-3">
            <div class="card-header"><h5 class="mb-0">Pipeline Breakdown</h5></div>
            <div class="card-body p-2">
                @foreach(\App\Models\CrmContact::$statuses as $key => $meta)
                    @if($byStatus->get($key, 0) > 0)
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="small">{{ $meta['label'] }}</span>
                            <span class="badge badge-light-{{ $meta['color'] }}">{{ $byStatus->get($key, 0) }}</span>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    </div>

</div>
@endsection
