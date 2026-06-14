@extends('layouts.admin')

@section('title', 'Sponsor Relationships')
@section('page-title', 'Sponsor Relationships')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.sponsors.index') }}">Sponsors</a></li>
    <li class="breadcrumb-item active">Relationships</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-sm-12">
            <div class="card">
                <div class="card-header">
                    <h5>All Sponsorship Relationships</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordernone">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Sponsor</th>
                                    <th>Sponsored</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($sponsorships as $sponsorship)
                                    <tr>
                                        <td>{{ $sponsorship->id }}</td>
                                        <td>{{ $sponsorship->sponsor->name ?? '—' }}</td>
                                        <td>{{ $sponsorship->sponsored->name ?? '—' }}</td>
                                        <td>
                                            @php $status = $sponsorship->status ?? 'pending'; @endphp
                                            <span class="badge badge-light-{{ $status === 'accepted' ? 'success' : ($status === 'rejected' ? 'danger' : 'warning') }}">
                                                {{ ucfirst($status) }}
                                            </span>
                                        </td>
                                        <td>{{ $sponsorship->created_at->format('d M Y') }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center f-light">No sponsorships found.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">{{ $sponsorships->links() }}</div>
                </div>
            </div>
        </div>
    </div>
@endsection
