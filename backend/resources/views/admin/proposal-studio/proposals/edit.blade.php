@extends('layouts.admin')

@section('title', 'Proposal v'.$proposal->version)
@section('page-title', $prospect->company_name.': proposal v'.$proposal->version)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.proposal-studio.prospects.index') }}">Proposal Studio</a></li>
    <li class="breadcrumb-item"><a href="{{ route('admin.proposal-studio.prospects.show', $prospect) }}">{{ $prospect->company_name }}</a></li>
    <li class="breadcrumb-item active">Proposal v{{ $proposal->version }}</li>
@endsection

@php
    $c = $proposal->content ?? [];
    $editable = $proposal->status === 'draft';
    $plan = array_pad($c['plan'] ?? [], max(count($c['plan'] ?? []) + 2, 3), []);
    $benefits = array_pad($c['benefits'] ?? [], max(count($c['benefits'] ?? []) + 1, 3), []);
    $chosen = array_map('intval', $c['claim_ids'] ?? []);
    $review = app(\App\Services\ProposalStudio\ProposalService::class)->needsVendorReview($proposal);
@endphp

@section('content')
<div class="container-fluid">
    @include('admin.proposal-studio._nav')

    @if($proposal->vendor_review === 'changes_requested')
        <div class="alert alert-warning"><strong>{{ $prospect->offering->vendor_name }} asked for changes:</strong> {!! nl2br(e($proposal->vendor_review_note)) !!}</div>
    @endif

    <form method="POST" action="{{ route('admin.proposal-studio.proposals.update', [$prospect, $proposal]) }}">
        @csrf @method('PUT')
        <fieldset @disabled(! $editable)>
        <div class="row g-3">
            <div class="col-xl-8">
                <div class="card">
                    <div class="card-body">
                        <p class="small f-light">Free text may use simple Markdown. Do not type performance, safety, health or test results here: pick them from the approved claims on the right, which are shown to the prospect word for word.</p>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold" for="pr-headline">Headline</label>
                            <input id="pr-headline" name="headline" class="form-control" maxlength="300" value="{{ old('headline', $c['headline'] ?? '') }}">
                        </div>
                        @foreach(['intro' => ['Opening note', 3], 'situation' => ['What we understand (their situation, in their words)', 5], 'how_it_works' => ['How it works for them', 5]] as $field => [$label, $rows])
                            <div class="mb-3">
                                <label class="form-label small fw-semibold" for="pr-{{ $field }}">{{ $label }}</label>
                                <textarea id="pr-{{ $field }}" name="{{ $field }}" rows="{{ $rows }}" class="form-control">{{ old($field, $c[$field] ?? '') }}</textarea>
                            </div>
                        @endforeach

                        <label class="form-label small fw-semibold">Recommendation, per site</label>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm align-top mb-0">
                                <thead><tr><th>Site</th><th>Product</th><th style="width:110px">Quantity</th><th>Why</th></tr></thead>
                                <tbody>
                                @foreach($plan as $i => $row)
                                    <tr>
                                        <td><input name="plan[{{ $i }}][site]" class="form-control form-control-sm" value="{{ $row['site'] ?? '' }}" aria-label="Site"></td>
                                        <td><input name="plan[{{ $i }}][product]" class="form-control form-control-sm" value="{{ $row['product'] ?? '' }}" aria-label="Product"></td>
                                        <td><input name="plan[{{ $i }}][quantity]" class="form-control form-control-sm" value="{{ $row['quantity'] ?? '' }}" aria-label="Quantity"></td>
                                        <td><textarea name="plan[{{ $i }}][rationale]" rows="2" class="form-control form-control-sm" aria-label="Why">{{ $row['rationale'] ?? '' }}</textarea></td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>

                        <label class="form-label small fw-semibold">Benefits for this business</label>
                        @foreach($benefits as $i => $b)
                            <div class="row g-2 mb-2">
                                <div class="col-md-4"><input name="benefits[{{ $i }}][title]" class="form-control form-control-sm" placeholder="Title" value="{{ $b['title'] ?? '' }}" aria-label="Benefit title"></div>
                                <div class="col-md-8"><textarea name="benefits[{{ $i }}][text]" rows="2" class="form-control form-control-sm" aria-label="Benefit text">{{ $b['text'] ?? '' }}</textarea></div>
                            </div>
                        @endforeach

                        <div class="mb-3 mt-3">
                            <label class="form-label small fw-semibold" for="pr-pricing">Investment (optional)</label>
                            <textarea id="pr-pricing" name="pricing" rows="3" class="form-control" placeholder="Only prices {{ $prospect->offering->vendor_name }} has confirmed in writing. Shown as estimates.">{{ old('pricing', $c['pricing'] ?? '') }}</textarea>
                        </div>
                        <div class="mb-0">
                            <label class="form-label small fw-semibold" for="pr-next">Next steps</label>
                            <textarea id="pr-next" name="next_steps" rows="3" class="form-control">{{ old('next_steps', $c['next_steps'] ?? '') }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card">
                    <div class="card-header"><h6 class="mb-0 fw-bold">Approved claims to show</h6></div>
                    <div class="card-body" style="max-height: 60vh; overflow:auto;">
                        @forelse($claims as $claim)
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="claim_ids[]" value="{{ $claim->id }}" id="cl-{{ $claim->id }}" @checked(in_array($claim->id, $chosen, true))>
                                <label class="form-check-label small" for="cl-{{ $claim->id }}">
                                    @if($claim->topic)<strong>{{ $claim->topic }}:</strong> @endif"{{ $claim->quote }}"
                                    <span class="f-light d-block">{{ $claim->document->title ?? '' }}</span>
                                </label>
                            </div>
                        @empty
                            <p class="small f-light mb-0">No claims are approved yet. <a href="{{ route('admin.proposal-studio.offerings.show', [$prospect->offering, 'tab' => 'claims']) }}">Review claims</a> first; without them the page shows no results.</p>
                        @endforelse
                    </div>
                </div>
                <div class="card">
                    <div class="card-body d-grid gap-2">
                        @if($editable)
                            <button class="btn btn-outline-primary" type="submit">Save draft</button>
                            <button class="btn btn-primary" type="submit" name="then_publish" value="1">{{ $review ? 'Save and send to '.$prospect->offering->vendor_name.' for review' : 'Save and publish' }}</button>
                            @if($review)<p class="small f-light mb-0">{{ $prospect->offering->vendor_name }} reviews the first {{ config('proposal-studio.vendor_review_first') }} proposals for this offering before they are published.</p>@endif
                        @else
                            <p class="small mb-0">This version is {{ mb_strtolower($proposal->statusLabel()) }} and cannot be edited.</p>
                        @endif
                        <a class="btn btn-outline-secondary" href="{{ route('admin.proposal-studio.proposals.preview', [$prospect, $proposal]) }}" target="_blank" rel="noopener">Preview as the prospect</a>
                    </div>
                </div>
            </div>
        </div>
        </fieldset>
    </form>
</div>
@endsection
