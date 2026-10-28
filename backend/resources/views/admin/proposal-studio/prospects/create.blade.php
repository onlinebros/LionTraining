@extends('layouts.admin')

@section('title', 'New prospect')
@section('page-title', 'New prospect')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.proposal-studio.prospects.index') }}">Proposal Studio</a></li>
    <li class="breadcrumb-item active">New prospect</li>
@endsection

@section('content')
<div class="container-fluid">
    @include('admin.proposal-studio._nav')

    <div class="card" style="max-width: 900px;">
        <div class="card-body">
            @if($offerings->isEmpty())
                <p class="mb-0">There is no active offering yet. <a href="{{ route('admin.proposal-studio.offerings.index') }}">Add one</a> first.</p>
            @else
            <form method="POST" action="{{ route('admin.proposal-studio.prospects.store') }}">
                @csrf
                @include('admin.proposal-studio.prospects._fields', ['prospect' => null])
                <div class="mt-3">
                    <label class="form-label small fw-semibold" for="ps-intake">Everything you know so far</label>
                    <textarea id="ps-intake" name="intake" rows="10" maxlength="20000" required
                              class="form-control @error('intake') is-invalid @enderror"
                              placeholder="Paste call notes, emails or anything from their website. How many locations, how big, what HVAC, who is in the space, what prompted the conversation, who decides…">{{ old('intake') }}</textarea>
                    @error('intake')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">The assistant reads this, sums up what it means, and asks for what is missing.</div>
                </div>
                <button type="submit" class="btn btn-primary mt-3">Start discovery</button>
            </form>
            @endif
        </div>
    </div>
</div>
@endsection
