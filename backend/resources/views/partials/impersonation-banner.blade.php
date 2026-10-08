@if (\App\Support\Impersonation::active())
    {{-- A borrowed session looks exactly like the member's own, so it must
         never be quiet about it. --}}
    <div class="container-fluid pt-3">
        <div class="alert alert-danger py-2 mb-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span>
                You are signed in as <strong>{{ auth()->user()->name }}</strong> ({{ auth()->user()->email }}).
                Anything you do here is done as them.
            </span>
            <form method="POST" action="{{ route('impersonation.stop') }}">
                @csrf
                <button class="btn btn-sm btn-light">Back to admin</button>
            </form>
        </div>
    </div>
@endif
