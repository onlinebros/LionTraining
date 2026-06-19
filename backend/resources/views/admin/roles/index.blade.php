@extends('layouts.admin')

@section('title', 'Roles')
@section('page-title', 'Roles & Permissions')

@section('breadcrumb')
    <li class="breadcrumb-item active">Roles</li>
@endsection

@section('content')
<div class="container-fluid">
    <div class="row">
        @php
        $colors = ['free_member' => 'info', 'paid_member' => 'success', 'support_admin' => 'warning', 'super_admin' => 'danger'];
        $icons  = ['free_member' => 'user', 'paid_member' => 'star', 'support_admin' => 'headphones', 'super_admin' => 'shield'];
        @endphp
        @foreach($roles as $role)
        <div class="col-xl-3 col-sm-6">
            <div class="card">
                <div class="card-body text-center py-4">
                    <div class="mb-3">
                        <span style="display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;border-radius:50%;background:var(--bs-{{ $colors[$role->name] ?? 'primary' }}-bg-subtle,#f0eeff);">
                            <i data-feather="{{ $icons[$role->name] ?? 'award' }}" style="width:24px;height:24px;color:var(--bs-{{ $colors[$role->name] ?? 'primary' }});"></i>
                        </span>
                    </div>
                    <h5 class="mb-1">{{ $role->display_name }}</h5>
                    <p class="text-muted small mb-3">{{ $role->description }}</p>
                    <div class="d-flex justify-content-center gap-3 mb-3">
                        <div>
                            <div class="fw-bold fs-4">{{ $role->users_count }}</div>
                            <small class="text-muted">Users</small>
                        </div>
                        <div>
                            <div class="fw-bold fs-4">{{ $role->level }}</div>
                            <small class="text-muted">Level</small>
                        </div>
                    </div>
                    @if($role->is_admin)
                        <span class="badge badge-light-warning">Admin Access</span>
                    @else
                        <span class="badge badge-light-info">Member</span>
                    @endif
                </div>
                <div class="card-footer bg-transparent text-center py-2">
                    <a href="{{ route('admin.users.index', ['role' => $role->name]) }}" class="btn btn-sm btn-outline-primary">
                        View Users
                    </a>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    <div class="card mt-2">
        <div class="card-header"><h5 class="mb-0">Role Summary</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Role</th>
                            <th>Slug</th>
                            <th>Level</th>
                            <th>Admin Access</th>
                            <th>Members</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($roles as $role)
                        <tr>
                            <td class="fw-semibold">{{ $role->display_name }}</td>
                            <td><code>{{ $role->name }}</code></td>
                            <td>{{ $role->level }}</td>
                            <td>
                                @if($role->is_admin)
                                    <span class="badge badge-light-success">Yes</span>
                                @else
                                    <span class="badge badge-light-secondary">No</span>
                                @endif
                            </td>
                            <td>{{ $role->users_count }}</td>
                            <td class="text-muted small">{{ $role->description }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
