@extends('layouts.admin')

@section('title', 'Accounts')
@section('page-title', 'Proposal Studio')

@section('breadcrumb')
    <li class="breadcrumb-item active">Proposal Studio</li>
@endsection

@section('content')
<div class="container-fluid">
    @include('admin.proposal-studio._nav')

    <div class="row g-3">
        <div class="col-xl-9">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="d-flex gap-2 flex-wrap">
                        @foreach(['all' => 'All'] + \App\Models\BusinessAccount::STAGES as $key => $label)
                            <a href="{{ route('admin.proposal-studio.accounts.index', ['stage' => $key, 'q' => $search ?: null]) }}"
                               class="btn btn-sm {{ $stage === $key ? 'btn-primary' : 'btn-outline-secondary' }}">
                                {{ $label }}
                                @if($key !== 'all')<span class="ms-1 opacity-75">{{ $counts[$key] ?? 0 }}</span>@endif
                            </a>
                        @endforeach
                    </div>
                    <div class="d-flex gap-2">
                        <form method="GET" action="{{ route('admin.proposal-studio.accounts.index') }}" role="search">
                            <input type="hidden" name="stage" value="{{ $stage }}">
                            <input type="search" name="q" value="{{ $search }}" class="form-control form-control-sm" placeholder="Find a company" aria-label="Find a company">
                        </form>
                        <a href="{{ route('admin.proposal-studio.accounts.create') }}" class="btn btn-primary btn-sm text-nowrap">New account</a>
                    </div>
                </div>
                <div class="card-body p-0">
                    @if($accounts->isEmpty())
                        <div class="p-4">
                            <p class="mb-1 fw-semibold">{{ $search ? 'No company matches that.' : 'No accounts yet.' }}</p>
                            <p class="f-light small mb-0">An account is a company we are building a relationship with: its people, a timeline, its opportunities, and a private microsite we build for them.</p>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table mb-0">
                                <thead><tr><th>Company</th><th>Stage</th><th>Next step</th><th class="text-end">Opportunities</th><th class="text-end">On the site</th><th>Owner</th><th>Updated</th></tr></thead>
                                <tbody>
                                @foreach($accounts as $account)
                                    <tr>
                                        <td><a href="{{ route('admin.proposal-studio.accounts.show', $account) }}">{{ $account->name }}</a>@if($account->industry)<div class="f-light small">{{ $account->industry }}</div>@endif</td>
                                        <td><span class="badge {{ $account->stageBadgeClass() }}">{{ $account->stageLabel() }}</span></td>
                                        <td class="small">
                                            {{ $account->next_step }}
                                            @if($account->next_step_due)<div class="{{ $account->next_step_due->lt(today()) ? 'text-danger' : 'f-light' }}">{{ $account->next_step_due->format('M j') }}</div>@endif
                                        </td>
                                        <td class="text-end">{{ $account->opportunities_count }}</td>
                                        <td class="text-end">{{ $account->members_count ?: '—' }}</td>
                                        <td class="small">{{ $account->owner?->name }}</td>
                                        <td class="f-light small">{{ $account->updated_at->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
            {{ $accounts->links() }}
        </div>

        <div class="col-xl-3">
            <div class="card">
                <div class="card-header"><h6 class="mb-0 fw-bold">Open tasks</h6></div>
                <div class="card-body">
                    @forelse($tasks as $task)
                        <div class="small py-1 border-bottom">
                            <a href="{{ route('admin.proposal-studio.accounts.show', $task->account) }}">{{ $task->account->name }}</a>
                            <div>{{ \Illuminate\Support\Str::limit($task->body, 120) }}</div>
                            @if($task->due_on)<div class="{{ $task->isOverdue() ? 'text-danger' : 'f-light' }}">Due {{ $task->due_on->format('M j') }}</div>@endif
                        </div>
                    @empty
                        <p class="f-light small mb-0">Nothing due. Add tasks from an account's timeline.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
