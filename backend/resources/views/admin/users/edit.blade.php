@extends('layouts.admin')

@section('title', 'Edit User')
@section('page-title', 'Edit User')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.users.index') }}">Users</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-sm-12 col-xl-8 offset-xl-2">
            <div class="card">
                <div class="card-header">
                    <h5>Edit User: {{ $user->name }}</h5>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.users.update', $user) }}" method="POST">
                        @csrf @method('PUT')
                        <div class="mb-3">
                            <label class="form-label">Name</label>
                            <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                                   value="{{ old('name', $user->name) }}" required>
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                                   value="{{ old('email', $user->email) }}" required>
                            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Role</label>
                            <select name="role_id" class="form-select @error('role_id') is-invalid @enderror">
                                @foreach(\App\Models\Role::orderBy('level')->get() as $role)
                                    <option value="{{ $role->id }}"
                                        {{ old('role_id', $user->role_id) == $role->id ? 'selected' : '' }}>
                                        {{ $role->display_name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('role_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password <small class="text-muted">(leave blank to keep current)</small></label>
                            <input type="password" name="password" class="form-control @error('password') is-invalid @enderror">
                            @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <hr class="my-4">
                        <h6 class="fw-bold mb-1">Training Access</h6>
                        <p class="text-muted small mb-3">
                            Setting an active start date enables time-based content release for this member.
                            Drip content becomes available after the configured number of days or months from this date.
                        </p>
                        <div class="mb-3">
                            <label class="form-label">Active Start Date</label>
                            <input type="date" name="active_start_date"
                                   class="form-control @error('active_start_date') is-invalid @enderror"
                                   value="{{ old('active_start_date', $user->active_start_date?->format('Y-m-d')) }}">
                            @error('active_start_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <small class="text-muted">
                                Leave blank to disable time-gated access for this member.
                                @if($user->active_start_date)
                                    Currently set to <strong>{{ $user->active_start_date->format('M j, Y') }}</strong>.
                                @endif
                            </small>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                            <a href="{{ route('admin.users.show', $user) }}" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
