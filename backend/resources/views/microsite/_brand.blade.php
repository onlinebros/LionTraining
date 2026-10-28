{{-- Co-branding for a microsite: the client's logo and colour in the band,
     with ours beside it. The colour is validated as #rrggbb when saved. --}}
@if($account->brand_color)
    @push('head')
    <style>
        :root { --band: {{ $account->brand_color }}; --band-text: {{ $account->brandInk() }}; }
        .band small { color: var(--band-text); opacity: .8; }
    </style>
    @endpush
@endif
@push('head')
<style>
    .client-logo { height: 40px; max-width: 180px; object-fit: contain; background: #fff; border-radius: 6px; padding: 4px 8px; }
    .with-q3 { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 500; opacity: .85; }
    .with-q3 img { width: 22px; height: 22px; }
</style>
@endpush

@section('brand')
    <span class="brand" style="gap: 14px; flex-wrap: wrap;">
        @if($account->logo_path)
            {{-- The admin's preview may come before the site has an address. --}}
            <img class="client-logo" src="{{ ($preview ?? false) ? route('admin.proposal-studio.accounts.logo', $account) : route('microsite.logo', $account->site_ref) }}?v={{ $account->updated_at?->timestamp }}" alt="{{ $account->name }}">
        @else
            <span>{{ $account->name }}</span>
        @endif
        <span class="with-q3"><span aria-hidden="true">with</span><img src="{{ \App\Support\Asset::v('assets/images/logo/q3-app-icon-192.png') }}" alt="">Quantum 3 Solution</span>
    </span>
@endsection
