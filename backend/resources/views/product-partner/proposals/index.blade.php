@extends('layouts.product-partner')

@section('title', 'Proposals')
@section('page-title', 'Proposals')

@php $ctx = !empty($vendorSwitch) ? ['vendor' => $vendor] : []; @endphp

@section('content')
@foreach(['success' => 'success', 'error' => 'danger'] as $flash => $class)
    @if(session($flash))<div class="alert alert-{{ $class }}">{{ session($flash) }}</div>@endif
@endforeach

<div class="card">
    <div class="card-header py-3">
        <h5 class="mb-0">Waiting for your review</h5>
        <span class="f-light small">Quantum 3 sends you its first proposals for {{ $vendorName }} before the prospect sees them. Check that your product and its claims are presented correctly, then approve or send it back with notes.</span>
    </div>
    <div class="card-body p-0">
        @if($waiting->isEmpty())
            <p class="f-light small p-4 mb-0">Nothing waiting.</p>
        @else
            <div class="table-responsive"><table class="table mb-0">
                <thead><tr><th>Prospect</th><th>Version</th><th>Sent by</th><th>Sent</th></tr></thead>
                <tbody>
                @foreach($waiting as $p)
                    <tr>
                        <td><a href="{{ route('product-partner.proposals.show', [$p] + $ctx) }}">{{ $p->prospect->company_name }}</a></td>
                        <td>v{{ $p->version }}</td>
                        <td class="small">{{ $p->publisher?->name }}</td>
                        <td class="small f-light">{{ $p->updated_at->diffForHumans() }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
</div>

<div class="card">
    <div class="card-header py-3">
        <h5 class="mb-0">Published</h5>
        <span class="f-light small">Proposals prospects can see now. Each shows the prospect's details so your team can help close it: quotes, site surveys and installation.</span>
    </div>
    <div class="card-body p-0">
        @if($published->isEmpty())
            <p class="f-light small p-4 mb-0">None yet.</p>
        @else
            <div class="table-responsive"><table class="table mb-0">
                <thead><tr><th>Prospect</th><th>Industry</th><th>Contact</th><th>Version</th><th>Published</th></tr></thead>
                <tbody>
                @foreach($published as $p)
                    <tr>
                        <td><a href="{{ route('product-partner.proposals.show', [$p] + $ctx) }}">{{ $p->prospect->company_name }}</a></td>
                        <td class="small">{{ $p->prospect->industry }}</td>
                        <td class="small">{{ $p->prospect->contact_name }}@if($p->prospect->contact_email)<br><span class="f-light">{{ $p->prospect->contact_email }}</span>@endif</td>
                        <td>v{{ $p->version }}</td>
                        <td class="small f-light">{{ $p->published_at?->diffForHumans() }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
</div>
@endsection
