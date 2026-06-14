@extends('layouts.admin')

@section('title', 'Sponsors')
@section('page-title', 'Sponsors')

@section('breadcrumb')
    <li class="breadcrumb-item active">Sponsors</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-sm-12">
            <div class="card">
                <div class="card-header">
                    <h5>All Sponsors</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordernone">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Sponsees</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($sponsors as $sponsor)
                                    <tr>
                                        <td>{{ $sponsor->id }}</td>
                                        <td>{{ $sponsor->name }}</td>
                                        <td>{{ $sponsor->email }}</td>
                                        <td>{{ $sponsor->sponsees_count }}</td>
                                        <td>
                                            <a href="{{ route('admin.users.show', $sponsor) }}" class="btn btn-sm btn-info">
                                                <i data-feather="eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center f-light">No sponsors found.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">{{ $sponsors->links() }}</div>
                </div>
            </div>
        </div>
    </div>
@endsection
