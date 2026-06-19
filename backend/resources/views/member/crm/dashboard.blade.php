@extends('layouts.member')

@section('title', 'My CRM')
@section('page-title', 'My CRM')

@section('breadcrumb')
    <li class="breadcrumb-item active">CRM</li>
@endsection

@section('content')

{{-- Stat cards --}}
<div class="row g-3 mb-4">
    <div class="col-xl-2 col-sm-4 col-6">
        <div class="card h-100">
            <div class="card-body text-center py-3">
                <i data-feather="users" class="text-primary mb-2" style="width:28px;height:28px;"></i>
                <h4 class="mb-0 fw-bold">{{ number_format($stats['total_contacts']) }}</h4>
                <p class="f-light mb-0 small">All Contacts</p>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-sm-4 col-6">
        <div class="card h-100">
            <div class="card-body text-center py-3">
                <i data-feather="user-plus" class="text-info mb-2" style="width:28px;height:28px;"></i>
                <h4 class="mb-0 fw-bold">{{ number_format($stats['new_leads']) }}</h4>
                <p class="f-light mb-0 small">New Leads</p>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-sm-4 col-6">
        <div class="card h-100">
            <div class="card-body text-center py-3">
                <i data-feather="trending-up" class="text-warning mb-2" style="width:28px;height:28px;"></i>
                <h4 class="mb-0 fw-bold">{{ number_format($stats['hot_leads']) }}</h4>
                <p class="f-light mb-0 small">Hot Leads</p>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-sm-4 col-6">
        <div class="card h-100">
            <div class="card-body text-center py-3">
                <i data-feather="check-circle" class="text-success mb-2" style="width:28px;height:28px;"></i>
                <h4 class="mb-0 fw-bold">{{ number_format($stats['customers']) }}</h4>
                <p class="f-light mb-0 small">Customers</p>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-sm-4 col-6">
        <div class="card h-100">
            <div class="card-body text-center py-3">
                <i data-feather="clock" class="text-warning mb-2" style="width:28px;height:28px;"></i>
                <h4 class="mb-0 fw-bold">{{ number_format($stats['followups_today']) }}</h4>
                <p class="f-light mb-0 small">Due Today</p>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-sm-4 col-6">
        <div class="card h-100">
            <div class="card-body text-center py-3">
                <i data-feather="alert-circle" class="text-danger mb-2" style="width:28px;height:28px;"></i>
                <h4 class="mb-0 fw-bold">{{ number_format($stats['overdue']) }}</h4>
                <p class="f-light mb-0 small">Overdue</p>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">

    {{-- Follow-ups today --}}
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i data-feather="clock" class="me-1" style="width:16px;height:16px;"></i> Follow-ups Due Today</h5>
                <a href="{{ route('member.crm.contacts.index') }}" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                @forelse($followupsToday as $fu)
                    <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom">
                        <span class="badge badge-light-{{ \App\Models\CrmFollowup::$priorities[$fu->priority]['color'] ?? 'secondary' }}">
                            {{ ucfirst($fu->priority) }}
                        </span>
                        <div class="flex-grow-1 min-w-0">
                            <a href="{{ route('member.crm.contacts.show', $fu->contact) }}" class="fw-semibold text-dark d-block text-truncate">
                                {{ $fu->title }}
                            </a>
                            <small class="f-light">{{ $fu->contact->full_name }} — {{ $fu->due_at->format('g:i A') }}</small>
                        </div>
                    </div>
                @empty
                    <p class="text-center f-light py-4 mb-0">No follow-ups due today. Great work!</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Overdue --}}
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i data-feather="alert-circle" class="me-1 text-danger" style="width:16px;height:16px;"></i> Overdue Follow-ups</h5>
                @if($overdueFollowups->count() > 0)
                    <span class="badge badge-light-danger">{{ $overdueFollowups->count() }}</span>
                @endif
            </div>
            <div class="card-body p-0">
                @forelse($overdueFollowups as $fu)
                    <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom">
                        <div class="text-danger small fw-semibold" style="min-width:80px;">{{ $fu->due_at->diffForHumans() }}</div>
                        <div class="flex-grow-1 min-w-0">
                            <a href="{{ route('member.crm.contacts.show', $fu->contact) }}" class="fw-semibold text-dark d-block text-truncate">
                                {{ $fu->title }}
                            </a>
                            <small class="f-light">{{ $fu->contact->full_name }}</small>
                        </div>
                    </div>
                @empty
                    <p class="text-center f-light py-4 mb-0">No overdue follow-ups.</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Recent contacts --}}
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Recent Contacts</h5>
                <a href="{{ route('member.crm.contacts.create') }}" class="btn btn-sm btn-primary">
                    <i data-feather="user-plus" data-width="13" data-height="13"></i> Add Contact
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Status</th>
                                <th>Next Follow-up</th>
                                <th>Added</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($recentContacts as $c)
                                <tr>
                                    <td>
                                        <a href="{{ route('member.crm.contacts.show', $c) }}" class="fw-semibold">
                                            {{ $c->full_name }}
                                        </a>
                                        @if($c->email)<div class="small f-light">{{ $c->email }}</div>@endif
                                    </td>
                                    <td><span class="badge badge-light-{{ $c->status_color }}">{{ $c->status_label }}</span></td>
                                    <td class="small {{ $c->isOverdue() ? 'text-danger fw-semibold' : 'text-muted' }}">
                                        {{ $c->next_followup_at?->format('M d') ?? '—' }}
                                    </td>
                                    <td class="small text-muted">{{ $c->created_at->diffForHumans() }}</td>
                                    <td>
                                        <a href="{{ route('member.crm.contacts.show', $c) }}" class="btn btn-info btn-sm">
                                            <i data-feather="eye" data-width="12" data-height="12"></i>
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center f-light py-4">
                                    No contacts yet. <a href="{{ route('member.crm.contacts.create') }}">Add your first contact</a>.
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Pipeline breakdown --}}
    <div class="col-xl-4">
        <div class="card">
            <div class="card-header"><h5 class="mb-0">My Pipeline</h5></div>
            <div class="card-body">
                @foreach(\App\Models\CrmContact::$statuses as $key => $meta)
                    @php $count = $byStatus->get($key, 0); @endphp
                    @if($count > 0)
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small">{{ $meta['label'] }}</span>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress" style="width:80px;height:6px;">
                                    <div class="progress-bar bg-{{ $meta['color'] }}"
                                         style="width:{{ $stats['total_contacts'] > 0 ? ($count/$stats['total_contacts']*100) : 0 }}%"></div>
                                </div>
                                <span class="badge badge-light-{{ $meta['color'] }}">{{ $count }}</span>
                            </div>
                        </div>
                    @endif
                @endforeach
                @if($stats['total_contacts'] === 0)
                    <p class="f-light text-center mb-0">No contacts yet.</p>
                @endif
            </div>
        </div>
    </div>

</div>
@endsection
