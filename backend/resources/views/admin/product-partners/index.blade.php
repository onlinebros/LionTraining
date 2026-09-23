@extends('layouts.admin')

@section('title', 'Product Partners')
@section('page-title', 'Product Partners')

@section('breadcrumb')
    <li class="breadcrumb-item active">Product Partners</li>
@endsection

@section('content')

@if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger py-2">{{ $errors->first() }}</div>@endif

<div class="card">
    <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="mb-0">Vendor accounts</h5>
            <span class="text-muted small">
                People at a vendor company with a login to their own section. Each one sees only
                the products linked to their account, and nothing of the member area.
            </span>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.product-partners.payments') }}">Payments</a>
            {{-- Opens it as yourself — every vendor at once. Use "View as" on a
                 row to see it the way one partner does. --}}
            <a class="btn btn-sm btn-outline-light" href="{{ route('product-partner.dashboard') }}">Open the portal</a>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr><th>Name</th><th>Email</th><th>Products linked</th><th>Active</th><th></th></tr>
            </thead>
            <tbody>
            @forelse ($partners as $partner)
                <tr>
                    <td>{{ $partner->name }}</td>
                    <td class="text-muted small">{{ $partner->email }}</td>
                    <td>
                        @forelse ($partner->productPartnerAssignments as $assignment)
                            <span class="badge bg-primary me-1">{{ $assignment->label() }}</span>
                        @empty
                            {{-- The role on its own grants nothing, and an
                                 account in this state sees a holding page. --}}
                            <span class="badge bg-warning">No products linked — cannot see anything</span>
                        @endforelse
                    </td>
                    <td>
                        @if ($partner->is_active)
                            <span class="badge bg-success">Yes</span>
                        @else
                            <span class="badge bg-danger">No</span>
                        @endif
                    </td>
                    <td class="text-end" style="min-width:200px;">
                        {{-- "View as" rather than a plain link to the portal:
                             an admin's own view holds every vendor, so it
                             answers "does the portal work" and not "what does
                             this company see". --}}
                        <form method="POST" action="{{ route('admin.product-partners.view-as', $partner) }}" class="d-inline">
                            @csrf
                            <button class="btn btn-sm btn-primary">View as</button>
                        </form>
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.users.show', $partner) }}">Manage</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-4">
                    No product partner accounts yet. Set a user's role to Product Partner on their
                    user page, then link the products they should see.
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($partners->hasPages())
        <div class="card-footer">{{ $partners->links() }}</div>
    @endif
</div>

@if ($strays->isNotEmpty())
    {{-- Grants on accounts that are no longer product partners. They grant
         nothing — ProductPartner::grants() checks the role first — but a stale
         row is worth clearing rather than leaving to be rediscovered. --}}
    <div class="card">
        <div class="card-header py-3">
            <h5 class="mb-0">Inactive grants</h5>
            <span class="text-muted small">
                These accounts hold a product link but are not on the Product Partner role, so
                the link does nothing. Remove it, or put them back on the role.
            </span>
        </div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead><tr><th>Account</th><th>Role</th><th>Grant</th><th></th></tr></thead>
                <tbody>
                @foreach ($strays as $stray)
                    <tr>
                        <td>{{ $stray->user?->name ?? '—' }}</td>
                        <td class="text-muted small">{{ $stray->user?->role?->display_name ?? '—' }}</td>
                        <td>{{ $stray->label() }}</td>
                        <td class="text-end">
                            @if ($stray->user)
                                <form method="POST"
                                      action="{{ route('admin.users.product-partner.remove', [$stray->user, $stray]) }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger">Remove</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@endsection
