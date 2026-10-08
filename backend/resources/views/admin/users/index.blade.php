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
                        <form method="GET" action="{{ route('admin.users.index') }}" class="d-flex gap-2 align-items-center flex-wrap">
                            {{-- Name, email, referral code or account number. --}}
                            <div class="input-group input-group-sm" style="width:auto;">
                                <input type="search" name="q" value="{{ $search }}" class="form-control"
                                       placeholder="Search name, email, code or #" aria-label="Search users"
                                       style="min-width:240px;" @if($search === '') autofocus @endif>
                                <button type="submit" class="btn btn-secondary">Search</button>
                                @if($search !== '')
                                    <a href="{{ route('admin.users.index', request()->except('q', 'page')) }}"
                                       class="btn btn-outline-secondary" title="Clear search">&times;</a>
                                @endif
                            </div>
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
                    @if($errors->has('error'))
                        <div class="alert alert-danger m-3">{{ $errors->first('error') }}</div>
                    @endif
                    @php
                    $isSuperAdmin = auth()->user()->isSuperAdmin();
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
                                        <td class="text-end text-nowrap">
                                            {{-- One menu instead of a row of buttons. Fixed
                                                 positioning so the menu is not clipped by
                                                 the table's scroll container. --}}
                                            <div class="dropdown">
                                                <button type="button" class="btn btn-primary btn-sm dropdown-toggle"
                                                        data-bs-toggle="dropdown" aria-expanded="false"
                                                        data-bs-popper-config='{"strategy":"fixed"}'>
                                                    Actions
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li>
                                                        <a class="dropdown-item" href="{{ route('admin.users.show', $user) }}">
                                                            <i data-feather="eye" data-width="14" data-height="14"></i> View
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item" href="{{ route('admin.users.edit', $user) }}">
                                                            <i data-feather="edit" data-width="14" data-height="14"></i> Edit
                                                        </a>
                                                    </li>
                                                    @if(\App\Support\Impersonation::refusal(auth()->user(), $user) === null)
                                                    <li>
                                                        <form method="POST" action="{{ route('admin.users.impersonate', $user) }}"
                                                              onsubmit="return confirm('Sign in as {{ addslashes($user->name) }}? You will be acting as them until you click Back to admin.')">
                                                            @csrf
                                                            <button type="submit" class="dropdown-item">
                                                                <i data-feather="log-in" data-width="14" data-height="14"></i> Impersonate
                                                            </button>
                                                        </form>
                                                    </li>
                                                    @endif
                                                    @if($user->id !== auth()->id())
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <form method="POST" action="{{ route('admin.users.toggle-active', $user) }}"
                                                              onsubmit="return confirm('{{ $user->is_active ? 'Deactivate' : 'Activate' }} {{ addslashes($user->name) }}?')">
                                                            @csrf @method('PATCH')
                                                            <button type="submit" class="dropdown-item">
                                                                <i data-feather="{{ $user->is_active ? 'user-x' : 'user-check' }}" data-width="14" data-height="14"></i>
                                                                {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                                                            </button>
                                                        </form>
                                                    </li>
                                                    @endif
                                                    @if($isSuperAdmin)
                                                    <li>
                                                        <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                                                              onsubmit="return confirm('Delete {{ addslashes($user->name) }}?')">
                                                            @csrf @method('DELETE')
                                                            <button type="submit" class="dropdown-item text-danger">
                                                                <i data-feather="trash-2" data-width="14" data-height="14"></i> Delete
                                                            </button>
                                                        </form>
                                                    </li>
                                                    @endif
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="10" class="text-center f-light py-4">
                                            {{ $search !== '' ? 'No users match "'.$search.'".' : 'No users found.' }}
                                        </td>
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
