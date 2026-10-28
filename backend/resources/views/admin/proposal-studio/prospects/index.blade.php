@extends('layouts.admin')

@section('title', 'Proposal Studio')
@section('page-title', 'Proposal Studio')

@section('breadcrumb')
    <li class="breadcrumb-item active">Proposal Studio</li>
@endsection

@section('content')
<div class="container-fluid">
    @include('admin.proposal-studio._nav')

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex gap-2 flex-wrap">
                @foreach(['all' => 'All'] + \App\Models\Prospect::STATUSES as $key => $label)
                    <a href="{{ route('admin.proposal-studio.prospects.index', ['status' => $key]) }}"
                       class="btn btn-sm {{ $status === $key ? 'btn-primary' : 'btn-outline-secondary' }}">
                        {{ $label }}
                        @if($key !== 'all')<span class="ms-1 opacity-75">{{ $counts[$key] ?? 0 }}</span>@endif
                    </a>
                @endforeach
            </div>
            <a href="{{ route('admin.proposal-studio.prospects.create') }}" class="btn btn-primary btn-sm">New prospect</a>
        </div>
        <div class="card-body p-0">
            @if($prospects->isEmpty())
                <div class="p-4">
                    <p class="mb-1 fw-semibold">No prospects yet.</p>
                    <p class="f-light small mb-0">Start one with everything you know about the company: notes from a call, an email, their website. The assistant asks for what is missing and drafts a plan and emails.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead><tr><th>Company</th><th>Offering</th><th>Industry</th><th>Status</th><th>Started by</th><th>Updated</th></tr></thead>
                        <tbody>
                        @foreach($prospects as $prospect)
                            <tr>
                                <td><a href="{{ route('admin.proposal-studio.prospects.show', $prospect) }}">{{ $prospect->company_name }}</a></td>
                                <td class="small">{{ $prospect->offering?->vendor_name }}</td>
                                <td class="small">{{ $prospect->industry }}</td>
                                <td>
                                    <span class="badge {{ $prospect->statusBadgeClass() }}">{{ $prospect->statusLabel() }}</span>
                                    @if($prospect->isAssistantBusy())<span class="badge badge-light-info">Assistant writing</span>@endif
                                </td>
                                <td class="small">{{ $prospect->creator?->name }}</td>
                                <td class="f-light small">{{ $prospect->updated_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
    {{ $prospects->links() }}
</div>
@endsection
