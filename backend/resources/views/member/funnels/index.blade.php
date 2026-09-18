@extends('layouts.member')

@section('title', 'Video flows')

@section('content')
<div class="container-fluid">
    <div class="page-title mb-3">
        <h3 class="mb-1">Video flows</h3>
        <p class="text-muted mb-0">
            Send someone a link and they pick their own way through. You see where they are, what
            they choose, and you can talk to them the whole time.
        </p>
    </div>

    @if($funnels->isEmpty())
        <div class="card"><div class="card-body text-center py-5">
            <h6 class="mb-2">Nothing to share yet</h6>
            <p class="text-muted mb-0">
                Head office releases flows for members to share. Check back, or ask them to
                release one.
            </p>
        </div></div>
    @else
        <div class="row g-3">
            @foreach($funnels as $funnel)
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column">
                            <h5 class="mb-1">{{ $funnel->title }}</h5>
                            <p class="text-muted mb-3" style="font-size:13.5px;">
                                {{ $funnel->description ?: 'A short set of videos they steer themselves.' }}
                            </p>

                            <div class="text-muted mb-3" style="font-size:12.5px;">
                                {{ $funnel->steps_count }} {{ Str::plural('video', $funnel->steps_count) }}
                                @if(($mine[$funnel->id] ?? 0) > 0)
                                    · {{ $mine[$funnel->id] }}
                                    {{ Str::plural('person', $mine[$funnel->id]) }} of yours inside
                                @endif
                            </div>

                            <div class="input-group input-group-sm mb-3 mt-auto">
                                <input type="text" class="form-control share-url" readonly
                                       value="{{ $funnel->shareUrlFor($user) }}">
                                <button class="btn btn-outline-secondary copy-link" type="button">Copy</button>
                            </div>

                            <a href="{{ route('member.funnels.show', $funnel) }}" class="btn btn-primary btn-sm">
                                See who is in it
                            </a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
(function () {
    Array.prototype.forEach.call(document.querySelectorAll('.copy-link'), function (btn) {
        btn.addEventListener('click', function () {
            var input = btn.parentNode.querySelector('.share-url');
            if (!input) return;

            input.select();
            navigator.clipboard.writeText(input.value).then(function () {
                btn.textContent = 'Copied';
                setTimeout(function () { btn.textContent = 'Copy'; }, 1600);
            }).catch(function () {});
        });
    });
})();
</script>
@endpush
