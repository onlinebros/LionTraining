@extends('layouts.admin')

@section('title', $user->name)
@section('page-title', $user->name)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.users.index') }}">Users</a></li>
    <li class="breadcrumb-item active">{{ $user->name }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-4">
            <div class="card">
                <div class="card-body text-center">
                    <img class="img-70 rounded-circle mb-3" src="{{ asset('assets/images/dashboard/profile.png') }}" alt="">
                    <h5>{{ $user->name }}</h5>
                    <p class="f-light">{{ $user->email }}</p>
                    <p><span class="badge badge-light-primary">Member since {{ $user->created_at->format('M Y') }}</span></p>
                    <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-primary">Edit User</a>
                </div>
            </div>
        </div>
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header"><h5>Sponsors ({{ $user->sponsors->count() }})</h5></div>
                <div class="card-body">
                    @forelse($user->sponsors as $sponsor)
                        <div class="d-flex align-items-center mb-2">
                            <img class="img-30 rounded-circle me-2" src="{{ asset('assets/images/dashboard/profile.png') }}" alt="">
                            <span>{{ $sponsor->name }}</span>
                        </div>
                    @empty
                        <p class="f-light">No sponsors.</p>
                    @endforelse
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h5>Sponsees ({{ $user->sponsees->count() }})</h5></div>
                <div class="card-body">
                    @forelse($user->sponsees as $sponsee)
                        <div class="d-flex align-items-center mb-2">
                            <img class="img-30 rounded-circle me-2" src="{{ asset('assets/images/dashboard/profile.png') }}" alt="">
                            <span>{{ $sponsee->name }}</span>
                        </div>
                    @empty
                        <p class="f-light">No sponsees.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection
