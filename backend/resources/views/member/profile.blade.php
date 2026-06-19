@extends('layouts.member')

@section('title', 'Profile & Settings')
@section('page-title', 'Profile & Settings')

@section('breadcrumb')
    <li class="breadcrumb-item active">Profile</li>
@endsection

@section('content')
@php
    $roleColors = ['free_member'=>'info','paid_member'=>'success','support_admin'=>'warning','super_admin'=>'danger'];
    $referralUrl = url('/join/' . $user->referral_code);
    $photoUrl = $user->profile_photo ? Storage::url($user->profile_photo) : null;
@endphp

<div class="row g-3">

    {{-- ── Profile sidebar ─────────────────────────────────── --}}
    <div class="col-xl-4">
        <div class="card mb-0">
            <div class="card-body text-center py-4">

                {{-- Avatar / Photo --}}
                <div class="position-relative d-inline-block mb-3">
                    @if($photoUrl)
                        <img src="{{ $photoUrl }}" alt="{{ $user->name }}"
                             style="width:84px;height:84px;border-radius:50%;object-fit:cover;border:3px solid var(--theme-default);">
                    @else
                        <div style="width:84px;height:84px;border-radius:50%;background:var(--theme-default);color:#fff;
                                    display:flex;align-items:center;justify-content:center;font-size:2rem;font-weight:700;margin:0 auto;">
                            {{ strtoupper(substr($user->name, 0, 1)) }}
                        </div>
                    @endif
                    {{-- Camera overlay --}}
                    <label for="photoInput" title="Change photo"
                           style="position:absolute;bottom:0;right:0;width:26px;height:26px;border-radius:50%;
                                  background:var(--theme-default);color:#fff;display:flex;align-items:center;
                                  justify-content:center;cursor:pointer;border:2px solid #fff;">
                        <i data-feather="camera" style="width:12px;height:12px;pointer-events:none;"></i>
                    </label>
                </div>

                <h5 class="fw-bold mb-0">{{ $user->name }}</h5>
                <p class="text-muted small mb-2">{{ $user->email }}</p>

                @if($user->role)
                    <span class="badge badge-light-{{ $roleColors[$user->role->name] ?? 'secondary' }}">
                        {{ $user->role->display_name }}
                    </span>
                @endif

                <hr class="my-3">

                <div class="text-start">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small">Member Since</span>
                        <span class="small fw-semibold">{{ $user->created_at->format('M d, Y') }}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small">Account Status</span>
                        <span class="badge badge-light-{{ $user->is_active ? 'success' : 'danger' }} small">
                            {{ $user->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small">Members Sponsored</span>
                        <span class="small fw-semibold">{{ $user->sponsees->count() ?? 0 }}</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted small">My Sponsors</span>
                        <span class="small fw-semibold">{{ $user->sponsors->count() ?? 0 }}</span>
                    </div>
                </div>

                <hr class="my-3">

                <div class="text-start">
                    <p class="text-muted small mb-1 fw-semibold">Referral Code</p>
                    <div class="d-flex align-items-center gap-2">
                        <code class="small flex-grow-1" style="background:var(--light-bg,#eef1f6);padding:5px 10px;border-radius:6px;display:block;">
                            {{ $user->referral_code }}
                        </code>
                        <button class="btn btn-sm btn-outline-primary py-0"
                                onclick="navigator.clipboard.writeText('{{ $referralUrl }}').then(()=>{this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',2000)})">
                            Copy
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Edit forms ───────────────────────────────────────── --}}
    <div class="col-xl-8">

        {{-- Personal info + Photo upload --}}
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0 fw-bold">Personal Information</h6></div>
            <div class="card-body">
                @if(session('success'))
                    <div class="alert alert-success py-2 alert-dismissible fade show">
                        {{ session('success') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                @endif

                <form method="POST" action="{{ route('member.profile.update') }}" enctype="multipart/form-data" id="profileForm">
                    @csrf

                    {{-- Hidden file input --}}
                    <input type="file" id="photoInput" name="profile_photo" accept="image/*" class="d-none">

                    {{-- Photo preview row --}}
                    <div class="d-flex align-items-center gap-3 mb-4 p-3 rounded" style="background:var(--light-bg,#f8f9fa);">
                        <div id="photoPreviewWrap">
                            @if($photoUrl)
                                <img id="photoPreview" src="{{ $photoUrl }}" alt="Photo"
                                     style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid var(--theme-default);">
                            @else
                                <div id="photoPreview"
                                     style="width:64px;height:64px;border-radius:50%;background:var(--theme-default);color:#fff;
                                            display:flex;align-items:center;justify-content:center;font-size:1.5rem;font-weight:700;">
                                    {{ strtoupper(substr($user->name, 0, 1)) }}
                                </div>
                            @endif
                        </div>
                        <div>
                            <p class="mb-1 small fw-semibold">Profile Photo</p>
                            <div class="d-flex gap-2 flex-wrap">
                                <label for="photoInput" class="btn btn-sm btn-outline-primary mb-0" style="cursor:pointer;">
                                    <i data-feather="upload" style="width:13px;height:13px;" class="me-1"></i>Upload Photo
                                </label>
                                @if($user->profile_photo)
                                <form method="POST" action="{{ route('member.profile.photo.remove') }}" class="d-inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                            onclick="return confirm('Remove your profile photo?')">
                                        <i data-feather="trash-2" style="width:13px;height:13px;" class="me-1"></i>Remove
                                    </button>
                                </form>
                                @endif
                            </div>
                            <p class="text-muted mb-0 mt-1" style="font-size:.75rem;">JPG, PNG, GIF or WebP — max 2MB</p>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Full Name</label>
                            <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                                   value="{{ old('name', $user->name) }}" required>
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Email Address</label>
                            <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                                   value="{{ old('email', $user->email) }}" required>
                            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Phone Number</label>
                            <input type="tel" name="phone" class="form-control @error('phone') is-invalid @enderror"
                                   value="{{ old('phone', $user->phone) }}" placeholder="e.g. +1 555 000 0000">
                            @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Address --}}
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0 fw-bold">Address</h6></div>
            <div class="card-body">
                @if(session('address_success'))
                    <div class="alert alert-success py-2 alert-dismissible fade show">
                        {{ session('address_success') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                @endif
                <form method="POST" action="{{ route('member.profile.address') }}">
                    @csrf
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Address Line 1</label>
                            <input type="text" name="address_line1" class="form-control @error('address_line1') is-invalid @enderror"
                                   value="{{ old('address_line1', $user->address_line1) }}" placeholder="Street address">
                            @error('address_line1') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Address Line 2 <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" name="address_line2" class="form-control @error('address_line2') is-invalid @enderror"
                                   value="{{ old('address_line2', $user->address_line2) }}" placeholder="Apt, suite, unit, etc.">
                            @error('address_line2') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">City</label>
                            <input type="text" name="city" class="form-control @error('city') is-invalid @enderror"
                                   value="{{ old('city', $user->city) }}" placeholder="City">
                            @error('city') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">State / Province</label>
                            <input type="text" name="state" class="form-control @error('state') is-invalid @enderror"
                                   value="{{ old('state', $user->state) }}" placeholder="State or province">
                            @error('state') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Postal Code</label>
                            <input type="text" name="postal_code" class="form-control @error('postal_code') is-invalid @enderror"
                                   value="{{ old('postal_code', $user->postal_code) }}" placeholder="ZIP / Postal code">
                            @error('postal_code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Country</label>
                            <input type="text" name="country" class="form-control @error('country') is-invalid @enderror"
                                   value="{{ old('country', $user->country) }}" placeholder="Country">
                            @error('country') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">Save Address</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Change password --}}
        <div class="card mb-0">
            <div class="card-header"><h6 class="mb-0 fw-bold">Change Password</h6></div>
            <div class="card-body">
                @if(session('password_success'))
                    <div class="alert alert-success py-2 alert-dismissible fade show">
                        {{ session('password_success') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                @endif
                @if($errors->has('current_password'))
                    <div class="alert alert-danger py-2">{{ $errors->first('current_password') }}</div>
                @endif
                <form method="POST" action="{{ route('member.profile.password') }}">
                    @csrf
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Current Password</label>
                            <input type="password" name="current_password"
                                   class="form-control @error('current_password') is-invalid @enderror"
                                   placeholder="Enter your current password" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">New Password</label>
                            <input type="password" name="password"
                                   class="form-control @error('password') is-invalid @enderror"
                                   placeholder="Min. 8 characters" required>
                            @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold">Confirm New Password</label>
                            <input type="password" name="password_confirmation"
                                   class="form-control" placeholder="Repeat new password" required>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-outline-primary">Update Password</button>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>

@push('scripts')
<script>
// Live photo preview when file is selected
document.getElementById('photoInput').addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function (e) {
        const wrap = document.getElementById('photoPreviewWrap');
        wrap.innerHTML = '<img id="photoPreview" src="' + e.target.result + '" alt="Preview" '
            + 'style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid var(--theme-default);">';
    };
    reader.readAsDataURL(file);

    // Auto-submit the form so the photo uploads immediately on select
    // (comment this out if you prefer a manual save)
    // document.getElementById('profileForm').submit();
});
</script>
@endpush

@endsection
