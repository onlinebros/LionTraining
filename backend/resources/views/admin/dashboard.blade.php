@extends('layouts.admin')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('breadcrumb')
    <li class="breadcrumb-item active">Dashboard</li>
@endsection

@section('content')
    <div class="row">

        {{-- Total Users --}}
        <div class="col-sm-6 col-xl-3">
            <div class="card o-hidden">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <p class="square-after f-w-600 h-45">Total Users<i class="fa-solid fa-square"></i></p>
                            <h4>{{ $stats['total_users'] ?? 0 }}</h4>
                        </div>
                        <div class="bg-gradient icon-box">
                            <i data-feather="users"></i>
                        </div>
                    </div>
                    <div class="progress-box mt-3">
                        <div class="progress sm-progress-bar progress-animate">
                            <div class="progress-gradient-primary" role="progressbar" style="width: 75%" aria-valuenow="75" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Active Sponsors --}}
        <div class="col-sm-6 col-xl-3">
            <div class="card o-hidden">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <p class="square-after f-w-600 h-45">Active Sponsors<i class="fa-solid fa-square"></i></p>
                            <h4>{{ $stats['active_sponsors'] ?? 0 }}</h4>
                        </div>
                        <div class="bg-gradient icon-box">
                            <i data-feather="star"></i>
                        </div>
                    </div>
                    <div class="progress-box mt-3">
                        <div class="progress sm-progress-bar progress-animate">
                            <div class="progress-gradient-secondary" role="progressbar" style="width: 60%" aria-valuenow="60" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Sponsorships --}}
        <div class="col-sm-6 col-xl-3">
            <div class="card o-hidden">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <p class="square-after f-w-600 h-45">Sponsorships<i class="fa-solid fa-square"></i></p>
                            <h4>{{ $stats['total_sponsorships'] ?? 0 }}</h4>
                        </div>
                        <div class="bg-gradient icon-box">
                            <i data-feather="link"></i>
                        </div>
                    </div>
                    <div class="progress-box mt-3">
                        <div class="progress sm-progress-bar progress-animate">
                            <div class="progress-gradient-success" role="progressbar" style="width: 45%" aria-valuenow="45" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Pending --}}
        <div class="col-sm-6 col-xl-3">
            <div class="card o-hidden">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <p class="square-after f-w-600 h-45">Pending Approvals<i class="fa-solid fa-square"></i></p>
                            <h4>{{ $stats['pending_sponsorships'] ?? 0 }}</h4>
                        </div>
                        <div class="bg-gradient icon-box">
                            <i data-feather="clock"></i>
                        </div>
                    </div>
                    <div class="progress-box mt-3">
                        <div class="progress sm-progress-bar progress-animate">
                            <div class="progress-gradient-warning" role="progressbar" style="width: 30%" aria-valuenow="30" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <div class="row">

        {{-- Recent Users --}}
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header">
                    <h5>Recent Users</h5>
                    <div class="card-header-right">
                        <ul class="list-unstyled card-option">
                            <li><i class="fa-solid fa-gear fa-spin"></i></li>
                            <li><i class="icofont icofont-maximize full-card"></i></li>
                            <li><i class="icofont icofont-minus minimize-card"></i></li>
                        </ul>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordernone">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Registered</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($recentUsers ?? [] as $user)
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="flex-shrink-0">
                                                    <img class="img-30 rounded-circle" src="{{ asset('assets/images/dashboard/profile.png') }}" alt="">
                                                </div>
                                                <div class="flex-grow-1 ms-2">
                                                    <p class="mb-0">{{ $user->name }}</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td>{{ $user->email }}</td>
                                        <td>{{ $user->created_at->diffForHumans() }}</td>
                                        <td><span class="badge badge-light-success">Active</span></td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center f-light">No users yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Quick Actions --}}
        <div class="col-xl-4">
            <div class="card">
                <div class="card-header">
                    <h5>Quick Actions</h5>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled">
                        <li class="mb-3">
                            <a href="{{ route('admin.users.create') }}" class="btn btn-primary w-100">
                                <i data-feather="user-plus" class="me-2"></i> Add New User
                            </a>
                        </li>
                        <li class="mb-3">
                            <a href="{{ route('admin.users.index') }}" class="btn btn-secondary w-100">
                                <i data-feather="users" class="me-2"></i> Manage Users
                            </a>
                        </li>
                        <li class="mb-3">
                            <a href="{{ route('admin.sponsors.index') }}" class="btn btn-success w-100">
                                <i data-feather="star" class="me-2"></i> View Sponsors
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('admin.sponsors.relationships') }}" class="btn btn-warning w-100">
                                <i data-feather="link" class="me-2"></i> Sponsor Relationships
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5>System Info</h5>
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            PHP Version
                            <span class="badge badge-light-primary">{{ PHP_VERSION }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            Laravel
                            <span class="badge badge-light-success">{{ app()->version() }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            Environment
                            <span class="badge badge-light-warning">{{ app()->environment() }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

    </div>
@endsection
