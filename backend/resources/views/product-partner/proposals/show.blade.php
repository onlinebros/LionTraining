@extends('layouts.product-partner')

@section('title', $prospect->company_name)
@section('page-title', $prospect->company_name.' · proposal v'.$proposal->version)

@php
    $ctx = !empty($vendorSwitch) ? ['vendor' => $vendor] : [];
    $c = $proposal->content ?? [];
    $md = fn (?string $text) => \Illuminate\Support\Str::markdown((string) $text, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
@endphp

@section('content')
<div class="row g-3">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0">{{ $c['headline'] ?? 'Proposal' }}</h5>
                <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="{{ route('product-partner.proposals.preview', [$proposal] + $ctx) }}">See it as the prospect does</a>
            </div>
            <div class="card-body">
                @if(!empty($c['situation']))<h6 class="fw-bold">Their situation</h6><div class="small mb-3">{!! $md($c['situation']) !!}</div>@endif
                @if(!empty($c['plan']))
                    <h6 class="fw-bold">Recommendation</h6>
                    <div class="table-responsive mb-3"><table class="table table-sm">
                        <thead><tr><th>Site</th><th>Product</th><th>Qty</th><th>Why</th></tr></thead>
                        <tbody>@foreach($c['plan'] as $row)<tr><td>{{ $row['site'] ?? '' }}</td><td>{{ $row['product'] ?? '' }}</td><td>{{ $row['quantity'] ?? '' }}</td><td class="small">{{ $row['rationale'] ?? '' }}</td></tr>@endforeach</tbody>
                    </table></div>
                @endif
                @if(!empty($c['how_it_works']))<h6 class="fw-bold">How it works for them</h6><div class="small mb-3">{!! $md($c['how_it_works']) !!}</div>@endif
                <h6 class="fw-bold">Your claims, as quoted ({{ $claims->count() }})</h6>
                @forelse($claims as $claim)
                    <div class="small border-start border-3 ps-2 mb-2">"{{ $claim->quote }}"@if($claim->footnote)<br><span class="f-light">{{ $claim->footnote }}</span>@endif<br><span class="f-light">From: {{ $claim->document->title ?? '' }}</span></div>
                @empty
                    <p class="small f-light">No claims quoted.</p>
                @endforelse
                @if(!empty($c['pricing']))<h6 class="fw-bold mt-3">Investment (shown as an estimate)</h6><div class="small">{!! $md($c['pricing']) !!}</div>@endif
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card">
            <div class="card-header"><h6 class="mb-0 fw-bold">The prospect</h6></div>
            <div class="card-body small">
                <strong>{{ $prospect->company_name }}</strong><br>
                @if($prospect->industry){{ $prospect->industry }}<br>@endif
                @if($prospect->website)<a href="{{ $prospect->website }}" rel="noopener noreferrer" target="_blank">{{ $prospect->website }}</a><br>@endif
                @if($prospect->contact_name || $prospect->contact_email)
                    <div class="mt-2">{{ $prospect->contact_name }}<br>{{ $prospect->contact_email }}<br>{{ $prospect->contact_phone }}</div>
                @endif
                @foreach($prospect->profile['sites'] ?? [] as $site)
                    <div class="mt-2"><strong>{{ $site['name'] ?? '' ?: 'Site' }}</strong>
                        @foreach(['location' => 'Location', 'sq_ft' => 'Sq ft', 'hvac' => 'HVAC'] as $k => $l)@if(filled($site[$k] ?? null) && is_scalar($site[$k]))<br>{{ $l }}: {{ $site[$k] }}@endif @endforeach
                    </div>
                @endforeach
            </div>
        </div>
        @if($submissions->isNotEmpty())
        <div class="card">
            <div class="card-header"><h6 class="mb-0 fw-bold">Requests from the prospect</h6></div>
            <div class="card-body small">
                @foreach($submissions as $s)
                    <div class="py-1 border-bottom"><strong>{{ $s->kindLabel() }}</strong> <span class="f-light">{{ $s->created_at->diffForHumans() }}</span>@if($s->body)<br>{{ $s->body }}@endif
                        @foreach($s->fields ?? [] as $label => $value)<br>{{ $label }}: {{ $value }}@endforeach
                    </div>
                @endforeach
            </div>
        </div>
        @endif
        @if($proposal->status === 'in_review')
        <div class="card">
            <div class="card-header"><h6 class="mb-0 fw-bold">Your review</h6></div>
            <div class="card-body">
                <form method="POST" action="{{ route('product-partner.proposals.review', [$proposal] + $ctx) }}">
                    @csrf
                    <textarea name="note" rows="4" class="form-control mb-2" maxlength="5000" placeholder="Notes for Quantum 3 (required if you ask for changes)">{{ old('note') }}</textarea>
                    @error('note')<div class="text-danger small mb-2">{{ $message }}</div>@enderror
                    <div class="d-flex gap-2">
                        <button class="btn btn-success btn-sm" name="decision" value="approve">Approve and publish</button>
                        <button class="btn btn-outline-warning btn-sm" name="decision" value="changes">Ask for changes</button>
                    </div>
                </form>
            </div>
        </div>
        @endif
    </div>
</div>
@endsection
