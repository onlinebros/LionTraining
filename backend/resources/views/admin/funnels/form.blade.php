@extends('layouts.admin')
@section('title', $funnel->exists ? 'Edit flow' : 'New flow')
@section('page-title', $funnel->exists ? $funnel->title : 'New flow')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.funnels.index') }}">Funnels</a></li>
    <li class="breadcrumb-item active">{{ $funnel->exists ? 'Edit' : 'New' }}</li>
@endsection

@section('content')
<form method="POST"
      action="{{ $funnel->exists ? route('admin.funnels.update', $funnel) : route('admin.funnels.store') }}">
    @csrf
    @if($funnel->exists) @method('PUT') @endif

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Details</h5></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label" for="title">What to call it</label>
                        <input type="text" name="title" id="title" required maxlength="160"
                               class="form-control @error('title') is-invalid @enderror"
                               value="{{ old('title', $funnel->title) }}"
                               placeholder="Partner overview">
                        @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="text-muted">Prospects see this on the page they land on.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="description">What it is about</label>
                        <textarea name="description" id="description" rows="3" class="form-control"
                                  placeholder="Optional. Shown before they give an email address.">{{ old('description', $funnel->description) }}</textarea>
                    </div>

                    <div class="form-check mb-2">
                        <input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1"
                               id="is_active" @checked(old('is_active', $funnel->exists ? $funnel->is_active : true))>
                        <label class="form-check-label" for="is_active">
                            Live — links to it work
                        </label>
                    </div>

                    <div class="form-check">
                        <input type="hidden" name="member_shareable" value="0">
                        <input class="form-check-input" type="checkbox" name="member_shareable" value="1"
                               id="member_shareable" @checked(old('member_shareable', $funnel->member_shareable))>
                        <label class="form-check-label" for="member_shareable">
                            Members can share it with their own link
                        </label>
                        <small class="text-muted d-block">
                            Leave off while you are still building it. Admins can share it either way.
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Next</h5></div>
                <div class="card-body">
                    <p class="text-muted mb-3" style="font-size:14px;line-height:1.7;">
                        Save this, then add the videos and decide what each one offers. You pick which
                        video everybody starts on; the rest is wherever their choices take them.
                    </p>

                    <div class="d-flex gap-2">
                        <button class="btn btn-primary">Save</button>
                        <a href="{{ $funnel->exists ? route('admin.funnels.show', $funnel) : route('admin.funnels.index') }}"
                           class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection
