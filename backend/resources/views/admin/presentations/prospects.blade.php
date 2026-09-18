@extends('layouts.admin')

@section('title', 'Prospects')
@section('page-title', 'Prospects')

@push('styles')
<style>
    .stat-row { display:grid; grid-template-columns:repeat(auto-fit, minmax(140px,1fr)); gap:10px; margin-bottom:16px; }
    .stat { background:var(--q3-surface); border:1px solid var(--q3-border); border-radius:10px; padding:13px 15px; }
    .stat__n { font-size:22px; font-weight:700; color:var(--q3-text); line-height:1.1; font-variant-numeric:tabular-nums; }
    .stat__l { font-size:11.5px; color:var(--q3-text-muted); text-transform:uppercase; letter-spacing:.06em; margin-top:3px; }

    .p-name { font-weight:600; color:var(--q3-text); }
    .p-sub { font-size:12px; color:var(--q3-text-muted); }
    .tag {
        display:inline-block; border-radius:999px; padding:2px 9px;
        font-size:11px; font-weight:600; white-space:nowrap;
    }
    .tag--won   { background:var(--q3-success-tint); color:#6ec49b; }
    .tag--warm  { background:var(--q3-warning-tint); color:#dcb262; }
    .tag--cold  { background:var(--q3-surface-3); color:var(--q3-text-muted); }
    .tag--alias { background:var(--q3-info-tint); color:#a2adb4; }

    .watched-list { font-size:12px; color:var(--q3-text-muted); }
    .watched-list li { margin-bottom:2px; }
</style>
@endpush

@section('content')
<div class="container-fluid">
    <div class="page-title mb-3 d-flex flex-wrap gap-3 align-items-center justify-content-between">
        <div>
            <h3 class="mb-1">Prospects</h3>
            <p class="text-muted mb-0">
                Everyone invited to a presentation, who invited them, what they watched, and who
                has signed up.
            </p>
        </div>
        <a href="{{ route('admin.presentations.index') }}" class="btn btn-sm btn-outline-secondary">
            Presentations
        </a>
    </div>

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

    <div class="stat-row">
        <div class="stat"><div class="stat__n">{{ $totals['prospects'] }}</div><div class="stat__l">Invited</div></div>
        <div class="stat"><div class="stat__n">{{ $totals['attended'] }}</div><div class="stat__l">Turned up</div></div>
        <div class="stat"><div class="stat__n">{{ $totals['clicked'] }}</div><div class="stat__l">Clicked through</div></div>
        <div class="stat"><div class="stat__n">{{ $totals['converted'] }}</div><div class="stat__l">Signed up</div></div>
        <div class="stat">
            <div class="stat__n">{{ $totals['conversion'] }}%</div>
            <div class="stat__l">Of those who attended</div>
        </div>
        <div class="stat"><div class="stat__n">{{ $totals['watch_hours'] }}h</div><div class="stat__l">Watched</div></div>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="GET" class="row g-2 mb-3">
                <div class="col-md-3">
                    <input type="text" name="q" value="{{ $filters['search'] }}" class="form-control"
                           placeholder="Search a name or email">
                </div>
                <div class="col-md-3">
                    <select name="host" class="form-select">
                        <option value="">Every member</option>
                        @foreach($hosts as $host)
                            <option value="{{ $host->id }}" @selected($filters['host_id'] == $host->id)>
                                {{ $host->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <select name="presentation" class="form-select">
                        <option value="">Every presentation</option>
                        @foreach($presentations as $p)
                            <option value="{{ $p->id }}" @selected($filters['presentation_id'] == $p->id)>
                                {{ $p->title }} — {{ $p->scheduledLabel() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 d-grid">
                    <button class="btn btn-outline-secondary">Filter</button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Person</th>
                            <th>Invited by</th>
                            <th>Watched</th>
                            <th>Attention</th>
                            <th>Where they are</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($prospects as $p)
                        <tr>
                            <td>
                                <div class="p-name">{{ $p['name'] }}</div>
                                <div class="p-sub">{{ $p['email'] }}</div>
                                @if($p['email_changed'])
                                    {{-- The case worth flagging: watched under one
                                         address, signed up under another. --}}
                                    <span class="tag tag--alias mt-1">
                                        Signed up as {{ $p['converted_email'] }}
                                    </span>
                                @endif
                            </td>
                            <td>{{ $p['host'] ?? '—' }}</td>
                            <td>
                                <ul class="watched-list mb-0 ps-3">
                                    @foreach($p['watched'] as $seen)
                                        <li>
                                            {{ $seen['title'] }}
                                            @if($seen['attended'])
                                                <span class="text-muted">
                                                    · in at {{ $seen['joined_at'] }} · {{ $seen['watched'] }}
                                                </span>
                                            @else
                                                <span class="text-muted">· registered, did not attend</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </td>
                            <td style="white-space:nowrap;">
                                <div>{{ $p['watch_label'] }}</div>
                                <div class="p-sub">
                                    {{ $p['attended_count'] }} of {{ $p['registrations'] }}
                                    {{ Str::plural('call', $p['registrations']) }}
                                </div>
                            </td>
                            <td>
                                @if($p['converted'])
                                    <span class="tag tag--won">Signed up</span>
                                    <div class="p-sub mt-1">
                                        {{ $p['converted_at']?->diffForHumans() }}
                                        @if($p['match'] === 'email')
                                            · matched by email
                                        @endif
                                    </div>
                                @elseif($p['clicked'])
                                    <span class="tag tag--warm">Clicked, no account</span>
                                @elseif($p['attended'])
                                    <span class="tag tag--cold">Watched</span>
                                @else
                                    <span class="tag tag--cold">Registered only</span>
                                @endif
                            </td>
                            <td class="text-end" style="white-space:nowrap;">
                                {{-- Deliberately read-only: a CRM contact belongs
                                     to the member who owns the relationship, and
                                     it is theirs to file. --}}
                                @if($p['crm_contact_id'])
                                    <span class="text-muted small">In their CRM</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
                                Nobody has registered for a presentation yet.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
