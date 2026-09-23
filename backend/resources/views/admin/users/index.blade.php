@extends('layouts.admin')

@section('title', 'Users')
@section('page-title', 'Users')

@section('breadcrumb')
    <li class="breadcrumb-item active">Users</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-sm-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0">All Users</h5>
                    <div class="d-flex gap-2 align-items-center">
                        {{-- Role and business-line filters. One GET form rather
                             than two onchange handlers that each throw the
                             other's parameter away. --}}
                        @php $roles = \App\Models\Role::orderBy('level')->get(); @endphp
                        <form method="GET" action="{{ route('admin.users.index') }}" class="d-flex gap-2 align-items-center">
                            <select name="role" class="form-select form-select-sm" style="width:auto;"
                                    onchange="this.form.submit()">
                                <option value="">All Roles</option>
                                @foreach($roles as $r)
                                    <option value="{{ $r->name }}" {{ request('role') === $r->name ? 'selected' : '' }}>
                                        {{ $r->display_name }}
                                    </option>
                                @endforeach
                            </select>

                            {{-- Which front door they came in through. The
                                 default line also matches every account that
                                 predates the feature — see the controller. --}}
                            <select name="opportunity" class="form-select form-select-sm" style="width:auto;"
                                    onchange="this.form.submit()">
                                <option value="">All Opportunities</option>
                                @foreach($opportunities as $key => $line)
                                    <option value="{{ $key }}" {{ $opportunity === $key ? 'selected' : '' }}>
                                        {{ $line->name() }}
                                    </option>
                                @endforeach
                            </select>
                            <noscript><button type="submit" class="btn btn-sm btn-secondary">Filter</button></noscript>
                        </form>
                        <a href="{{ route('admin.users.create') }}" class="btn btn-primary btn-sm">
                            <i data-feather="user-plus" data-width="14" data-height="14"></i> Add User
                        </a>
                    </div>
                </div>
                <div class="card-body p-0">
                    @if(session('success'))
                        <div class="alert alert-success m-3">{{ session('success') }}</div>
                    @endif
                    @php
                    $roleColors = ['free_member'=>'info','paid_member'=>'success','support_admin'=>'warning','super_admin'=>'danger'];
                    @endphp
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Opportunity</th>
                                    <th>Status</th>
                                    <th>Sponsees</th>
                                    <th>Sponsors</th>
                                    <th>Registered</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($users as $user)
                                    <tr>
                                        <td class="text-muted small">{{ $user->id }}</td>
                                        <td class="fw-semibold">{{ $user->name }}</td>
                                        <td class="text-muted">{{ $user->email }}</td>
                                        <td>
                                            @if($user->role)
                                                <span class="badge badge-light-{{ $roleColors[$user->role->name] ?? 'secondary' }}">
                                                    {{ $user->role->display_name }}
                                                </span>
                                            @else
                                                <span class="badge badge-light-secondary">No Role</span>
                                            @endif
                                        </td>
                                        {{-- The line they came in for. A member
                                             on the default is left plain: it is
                                             most of the list, and badging all of
                                             them badges none of them. --}}
                                        <td>
                                            @if($user->opportunity()->isDefault())
                                                <span class="text-muted small">{{ $user->opportunity()->shortName() }}</span>
                                            @else
                                                <span class="badge badge-light-primary">{{ $user->opportunity()->shortName() }}</span>
                                            @endif
                                            @if($user->entry_site)
                                                <div class="text-muted" style="font-size:.7rem;">{{ $user->entry_site }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            @if($user->is_active)
                                                <span class="badge badge-light-success">Active</span>
                                            @else
                                                <span class="badge badge-light-danger">Inactive</span>
                                            @endif
                                        </td>
                                        <td>{{ $user->sponsees_count }}</td>
                                        <td>{{ $user->sponsors_count }}</td>
                                        <td class="text-muted small">{{ $user->registeredAt()?->format('d M Y') }}</td>
                                        <td class="text-nowrap">
                                            <div class="d-flex gap-1 justify-content-end">
                                                <a href="{{ route('admin.users.show', $user) }}"
                                                   class="btn btn-info btn-sm">
                                                    <i data-feather="eye" data-width="14" data-height="14"></i> View
                                                </a>
                                                <a href="{{ route('admin.users.edit', $user) }}"
                                                   class="btn btn-warning btn-sm">
                                                    <i data-feather="edit" data-width="14" data-height="14"></i> Edit
                                                </a>
                                                @if($user->id !== auth()->id())
                                                <form method="POST" action="{{ route('admin.users.toggle-active', $user) }}" class="d-inline">
                                                    @csrf @method('PATCH')
                                                    <button type="submit"
                                                            class="btn btn-sm {{ $user->is_active ? 'btn-secondary' : 'btn-success' }}"
                                                            onclick="return confirm('{{ $user->is_active ? 'Deactivate' : 'Activate' }} {{ $user->name }}?')">
                                                        <i data-feather="{{ $user->is_active ? 'user-x' : 'user-check' }}" data-width="14" data-height="14"></i>
                                                        {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                                                    </button>
                                                </form>
                                                @endif
                                                @if(auth()->user()->isSuperAdmin())
                                                <form method="POST" action="{{ route('admin.users.destroy', $user) }}" class="d-inline"
                                                      onsubmit="return confirm('Delete {{ $user->name }}?')">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="btn btn-danger btn-sm">
                                                        <i data-feather="trash-2" data-width="14" data-height="14"></i> Delete
                                                    </button>
                                                </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="10" class="text-center f-light py-4">No users found.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3">{{ $users->links() }}</div>
                </div>
            </div>
        </div>
    </div>
@endsection
