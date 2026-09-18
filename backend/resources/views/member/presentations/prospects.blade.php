@extends('layouts.member')

@section('title', 'Prospects')

@push('styles')
<style>
    .stat-row { display:grid; grid-template-columns:repeat(auto-fit, minmax(140px,1fr)); gap:10px; margin-bottom:16px; }
    .stat { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:13px 15px; }
    .stat__n { font-size:22px; font-weight:700; color:#0f172a; line-height:1.1; font-variant-numeric:tabular-nums; }
    .stat__l { font-size:11.5px; color:#64748b; text-transform:uppercase; letter-spacing:.06em; margin-top:3px; }

    .p-name { font-weight:600; color:#0f172a; }
    .p-sub { font-size:12px; color:#64748b; }
    .tag {
        display:inline-block; border-radius:999px; padding:2px 9px;
        font-size:11px; font-weight:600; white-space:nowrap;
    }
    .tag--won   { background:#dcfce7; color:#166534; }
    .tag--warm  { background:#fef3c7; color:#92400e; }
    .tag--cold  { background:#f1f5f9; color:#64748b; }
    .tag--alias { background:#ede9fe; color:#5b21b6; }

    .watched-list { font-size:12px; color:#475569; }
    .watched-list li { margin-bottom:2px; }
</style>
@endpush

@section('content')
<div class="container-fluid">
    <div class="page-title mb-3 d-flex flex-wrap gap-3 align-items-center justify-content-between">
        <div>
            <h3 class="mb-1">Prospects</h3>
            <p class="text-muted mb-0">
                Everyone you have invited, what they have watched, and who has signed up.
            </p>
        </div>
        <a href="{{ route('member.presentations.index') }}" class="btn btn-sm btn-outline-secondary">
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
                <div class="col-md-5">
                    <input type="text" name="q" value="{{ $filters['search'] }}" class="form-control"
                           placeholder="Search a name or email">
                </div>
                <div class="col-md-5">
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
                                @if($p['crm_contact_id'])
                                    <span class="text-muted small">In your CRM</span>
                                @else
                                    <form method="POST"
                                          action="{{ route('member.presentations.prospects.crm', $p['attendee_id']) }}">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-primary">Add to CRM</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-5">
                                Nobody yet. Share your link from a presentation and they will appear here.
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
