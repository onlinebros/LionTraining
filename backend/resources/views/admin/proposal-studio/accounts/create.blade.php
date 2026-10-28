@extends('layouts.admin')

@section('title', 'New account')
@section('page-title', 'New account')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.proposal-studio.accounts.index') }}">Proposal Studio</a></li>
    <li class="breadcrumb-item active">New account</li>
@endsection

@section('content')
<div class="container-fluid">
    @include('admin.proposal-studio._nav')

    <div class="card" style="max-width: 900px;">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.proposal-studio.accounts.store') }}">
                @csrf
                @include('admin.proposal-studio.accounts._fields', ['account' => null])

                <h6 class="fw-bold small text-uppercase f-light mt-4">First contact <span class="fw-normal text-lowercase">(optional)</span></h6>
                <div class="row g-3">
                    <div class="col-md-4"><input type="text" name="contact[name]" maxlength="255" class="form-control" placeholder="Name" value="{{ old('contact.name') }}"></div>
                    <div class="col-md-4"><input type="text" name="contact[title]" maxlength="255" class="form-control" placeholder="Job title" value="{{ old('contact.title') }}"></div>
                    <div class="col-md-4">
                        <select name="contact[role]" class="form-select" aria-label="Their part in the decision">
                            @foreach(\App\Models\BusinessContact::ROLES as $key => $label)
                                <option value="{{ $key }}" @selected(old('contact.role', 'champion') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6"><input type="email" name="contact[email]" maxlength="255" class="form-control @error('contact.email') is-invalid @enderror" placeholder="Email" value="{{ old('contact.email') }}"></div>
                    <div class="col-md-6"><input type="text" name="contact[phone]" maxlength="40" class="form-control" placeholder="Phone" value="{{ old('contact.phone') }}"></div>
                </div>
                <button type="submit" class="btn btn-primary mt-3">Create account</button>
            </form>
        </div>
    </div>
</div>
@endsection
