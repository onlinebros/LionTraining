@extends('layouts.admin')
@section('title', 'Calls to action')
@section('page-title', 'Calls to action')

@section('breadcrumb')
    <li class="breadcrumb-item active">Calls to action</li>
@endsection

@section('content')
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="mb-1">The library</h5>
            <small class="text-muted">
                What a guest can be asked to do. Written once here, then placed on a video at the
                moment it becomes relevant. Every destination carries the inviting member's code,
                so whichever one a prospect takes, the same person gets the credit.
            </small>
        </div>
        <a href="{{ route('admin.cta-items.create') }}" class="btn btn-primary">New call to action</a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Asks for</th>
                        <th>Button</th>
                        <th>Used on</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @forelse($items as $item)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $item->name }}</div>
                            @if(! $item->is_active)
                                <span class="badge bg-secondary">Switched off</span>
                            @endif
                        </td>
                        <td>{{ $item->kindLabel() }}</td>
                        <td>
                            <span class="badge bg-light text-dark">{{ $item->effectiveLabel() }}</span>
                            <div class="text-muted small">
                                {{ $item->opens_in === \App\Support\PresentationCta::OPENS_SAME
                                    ? 'goes to the page' : 'opens beside the video' }}
                            </div>
                            @if($item->url)
                                <div class="text-muted small text-truncate" style="max-width:280px;">
                                    {{ $item->url }}
                                </div>
                            @endif
                        </td>
                        <td>
                            @if($item->cues_count)
                                {{ $item->cues_count }} {{ Str::plural('video', $item->cues_count) }}
                            @else
                                <span class="text-muted">Nowhere yet</span>
                            @endif
                        </td>
                        <td class="text-end" style="white-space:nowrap;">
                            <a href="{{ route('admin.cta-items.edit', $item) }}"
                               class="btn btn-sm btn-outline-secondary">Edit</a>
                            <form method="POST" action="{{ route('admin.cta-items.destroy', $item) }}"
                                  class="d-inline"
                                  onsubmit="return confirm('Delete “{{ $item->name }}”?');">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">
                            Nothing here yet. Add "Book a call", "Get the report" or "Join as a member"
                            and they become available on every video.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
