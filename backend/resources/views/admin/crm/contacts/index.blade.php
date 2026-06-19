@extends('layouts.admin')

@section('title', 'CRM Contacts')
@section('page-title', 'CRM Contacts')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.crm.dashboard') }}">CRM</a></li>
    <li class="breadcrumb-item active">Contacts</li>
@endsection

@section('content')
<div class="row">
    <div class="col-sm-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0">All Contacts</h5>
                <a href="{{ route('admin.crm.contacts.create') }}" class="btn btn-primary btn-sm">
                    <i data-feather="user-plus" data-width="14" data-height="14"></i> Add Contact
                </a>
            </div>

            {{-- Filters --}}
            <div class="card-body border-bottom pb-3">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <input type="text" name="search" class="form-control form-control-sm"
                               placeholder="Name, email, phone, company…" value="{{ request('search') }}">
                    </div>
                    <div class="col-md-2">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Statuses</option>
                            @foreach(\App\Models\CrmContact::$statuses as $key => $meta)
                                <option value="{{ $key }}" {{ request('status') === $key ? 'selected' : '' }}>
                                    {{ $meta['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="contact_type" class="form-select form-select-sm">
                            <option value="">All Types</option>
                            @foreach(\App\Models\CrmContact::$contactTypes as $key => $label)
                                <option value="{{ $key }}" {{ request('contact_type') === $key ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="owner" class="form-select form-select-sm">
                            <option value="">All Affiliates</option>
                            @foreach($owners as $o)
                                <option value="{{ $o->id }}" {{ request('owner') == $o->id ? 'selected' : '' }}>{{ $o->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="tag" class="form-select form-select-sm">
                            <option value="">All Tags</option>
                            @foreach($tags as $t)
                                <option value="{{ $t->id }}" {{ request('tag') == $t->id ? 'selected' : '' }}>{{ $t->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-1 d-flex gap-1">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i data-feather="search" data-width="14" data-height="14"></i>
                        </button>
                        <a href="{{ route('admin.crm.contacts.index') }}" class="btn btn-outline-secondary btn-sm">
                            <i data-feather="x" data-width="14" data-height="14"></i>
                        </a>
                    </div>
                </form>
            </div>

            <div class="card-body p-0">
                @if(session('success'))
                    <div class="alert alert-success m-3">{{ session('success') }}</div>
                @endif

                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Lead Source</th>
                                <th>Tags</th>
                                <th>Owner</th>
                                <th>Next Follow-up</th>
                                <th>Notes</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($contacts as $c)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.crm.contacts.show', $c) }}" class="fw-semibold text-dark">
                                            {{ $c->full_name }}
                                        </a>
                                        @if($c->email)
                                            <div class="small f-light">{{ $c->email }}</div>
                                        @endif
                                        @if($c->phone)
                                            <div class="small f-light">{{ $c->phone }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge badge-light-secondary small">
                                            {{ \App\Models\CrmContact::$contactTypes[$c->contact_type] ?? $c->contact_type }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-light-{{ $c->status_color }}">{{ $c->status_label }}</span>
                                        @if($c->isOverdue())
                                            <div class="badge badge-light-danger mt-1">Overdue</div>
                                        @endif
                                    </td>
                                    <td class="text-muted small">
                                        {{ \App\Models\CrmContact::$leadSources[$c->lead_source] ?? '—' }}
                                    </td>
                                    <td>
                                        @foreach($c->tags->take(3) as $tag)
                                            <span class="badge me-1" style="background:{{ $tag->color }};color:#fff;font-size:.65rem;">
                                                {{ $tag->name }}
                                            </span>
                                        @endforeach
                                        @if($c->tags->count() > 3)
                                            <span class="badge badge-light-secondary">+{{ $c->tags->count() - 3 }}</span>
                                        @endif
                                    </td>
                                    <td class="small text-muted">{{ $c->owner?->name ?? '—' }}</td>
                                    <td class="small {{ $c->isOverdue() ? 'text-danger fw-semibold' : 'text-muted' }}">
                                        {{ $c->next_followup_at?->format('M d, Y') ?? '—' }}
                                    </td>
                                    <td class="text-center text-muted small">{{ $c->notes_count }}</td>
                                    <td class="text-nowrap">
                                        <div class="d-flex gap-1 justify-content-end">
                                            <a href="{{ route('admin.crm.contacts.show', $c) }}"
                                               class="btn btn-info btn-sm">
                                                <i data-feather="eye" data-width="13" data-height="13"></i>
                                            </a>
                                            <a href="{{ route('admin.crm.contacts.edit', $c) }}"
                                               class="btn btn-warning btn-sm">
                                                <i data-feather="edit" data-width="13" data-height="13"></i>
                                            </a>
                                            <form method="POST" action="{{ route('admin.crm.contacts.destroy', $c) }}"
                                                  onsubmit="return confirm('Delete {{ $c->full_name }}?')">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-danger btn-sm">
                                                    <i data-feather="trash-2" data-width="13" data-height="13"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center f-light py-4">No contacts found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-3">{{ $contacts->links() }}</div>
            </div>
        </div>
    </div>
</div>
@endsection
