{{-- Flash messages and the two halves of the Proposal Studio. --}}
@foreach(['success' => 'success', 'error' => 'danger'] as $flash => $class)
    @if(session($flash))
        <div class="alert alert-{{ $class }} alert-dismissible fade show" role="alert">
            {{ session($flash) }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
@endforeach
<div class="d-flex gap-2 flex-wrap mb-3">
    <a href="{{ route('admin.proposal-studio.prospects.index') }}"
       class="btn btn-sm {{ request()->routeIs('admin.proposal-studio.prospects.*') ? 'btn-primary' : 'btn-outline-secondary' }}">Prospects</a>
    <a href="{{ route('admin.proposal-studio.offerings.index') }}"
       class="btn btn-sm {{ request()->routeIs('admin.proposal-studio.offerings.*') ? 'btn-primary' : 'btn-outline-secondary' }}">Offerings &amp; knowledge</a>
</div>
