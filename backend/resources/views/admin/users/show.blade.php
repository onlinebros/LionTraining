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
                    @if(session('success'))
                        <div class="alert alert-success text-start mb-3">{{ session('success') }}</div>
                    @endif
                    <img class="img-70 rounded-circle mb-3" src="{{ asset('assets/images/dashboard/profile.png') }}" alt="">
                    <h5>{{ $user->name }}</h5>
                    <p class="f-light mb-1">{{ $user->email }}</p>

                    {{-- Role badge --}}
                    @php $roleColors = ['free_member'=>'info','paid_member'=>'success','support_admin'=>'warning','super_admin'=>'danger']; @endphp
                    @if($user->role)
                        <span class="badge badge-light-{{ $roleColors[$user->role->name] ?? 'secondary' }} mb-2">
                            {{ $user->role->display_name }}
                        </span>
                    @endif

                    {{-- Active status badge --}}
                    <div class="mb-3">
                        @if($user->is_active)
                            <span class="badge badge-light-success">Active</span>
                        @else
                            <span class="badge badge-light-danger">Deactivated</span>
                        @endif
                        <span class="badge badge-light-primary ms-1">Member since {{ $user->created_at->format('M Y') }}</span>
                    </div>

                    <div class="d-flex justify-content-center gap-2 flex-wrap">
                        <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-primary btn-sm">Edit</a>

                        @if($user->id !== auth()->id())
                        <form method="POST" action="{{ route('admin.users.toggle-active', $user) }}">
                            @csrf @method('PATCH')
                            <button type="submit"
                                    class="btn btn-sm {{ $user->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}"
                                    onclick="return confirm('{{ $user->is_active ? 'Deactivate this user?' : 'Reactivate this user?' }}')">
                                {{ $user->is_active ? 'Deactivate' : 'Reactivate' }}
                            </button>
                        </form>
                        @endif
                    </div>
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
