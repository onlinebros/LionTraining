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
                        <span class="badge badge-light-primary ms-1">Member since {{ $user->registeredAt()?->format('M Y') }}</span>
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

            {{-- Business lines. What this member joined us for, which decides
                 what the back office shows them and whether a card is ever
                 asked for — see config/opportunities.php. --}}
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0">Opportunities</h5>
                    <span class="text-muted small">
                        Came in through
                        <strong>{{ $user->entry_site ?? $user->opportunity()->site() ?? 'an unrecorded door' }}</strong>
                    </span>
                </div>
                <div class="card-body">

                    @if($errors->has('error'))
                        <div class="alert alert-danger py-2 small">{{ $errors->first('error') }}</div>
                    @endif

                    <div class="table-responsive mb-3">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Line</th>
                                    <th>Training Program</th>
                                    <th>Added</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($user->opportunities() as $line)
                                    @php $row = $user->opportunityAssociations->firstWhere('opportunity', $line->key); @endphp
                                    <tr>
                                        <td>
                                            <span class="fw-semibold">{{ $line->name() }}</span>
                                            @if($line->key === $user->opportunityKey())
                                                <span class="badge badge-light-primary ms-1">Primary</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($line->requiresMembership())
                                                <span class="badge badge-light-warning">Card required</span>
                                            @else
                                                <span class="badge badge-light-success">No card</span>
                                            @endif
                                        </td>
                                        <td class="text-muted small">
                                            @if($row)
                                                {{ $row->source }}
                                                @if($row->addedBy) by {{ $row->addedBy->name }} @endif
                                                &middot; {{ $row->created_at?->format('d M Y') }}
                                            @else
                                                {{-- No join row: this is the default line, held by
                                                     every account that predates the feature. --}}
                                                <span class="fst-italic">default</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @if($row && $line->key !== $user->opportunityKey())
                                                <form method="POST" class="d-inline"
                                                      action="{{ route('admin.users.opportunities.remove', [$user, $line->key]) }}"
                                                      onsubmit="return confirm('Remove {{ $line->name() }} from {{ $user->name }}?');">
                                                    @csrf @method('DELETE')
                                                    <button class="btn btn-outline-danger btn-xs" type="submit">Remove</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <form method="POST" action="{{ route('admin.users.opportunities.add', $user) }}"
                          class="d-flex flex-wrap gap-2 align-items-center">
                        @csrf
                        <select name="opportunity" class="form-select form-select-sm" style="width:auto;" required>
                            @foreach($opportunities as $key => $line)
                                <option value="{{ $key }}">{{ $line->name() }}</option>
                            @endforeach
                        </select>
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="primary" value="1" id="opp-primary">
                            <label class="form-check-label small" for="opp-primary">Make primary</label>
                        </div>
                        <button class="btn btn-primary btn-sm" type="submit">Add</button>
                        <span class="text-muted small">
                            The primary line decides whether this member is asked for a card.
                        </span>
                    </form>
                </div>
            </div>

            {{-- Product Partner access.
                 Shown for every account so the section can be found, but the
                 grant form only appears once the role is set: linking products
                 to an account that is not a product partner does nothing, and a
                 form that silently achieves nothing is worse than no form. --}}
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Product Partner access</h5>
                    <a class="btn btn-outline-primary btn-xs" href="{{ route('admin.product-partners.index') }}">All partners</a>
                </div>
                <div class="card-body">
                    @if (! $user->isProductPartner())
                        <p class="text-muted small mb-0">
                            This account is a <strong>{{ $user->role?->display_name ?? 'member' }}</strong>.
                            To give someone at a vendor company their own section, change their role to
                            <strong>Product Partner</strong> on the Edit screen first. Doing it here would
                            move them out of the member area as a side effect of ticking a product.
                        </p>
                    @else
                        <div class="table-responsive mb-3">
                            <table class="table table-sm mb-0">
                                <thead class="table-light">
                                    <tr><th>Vendor &amp; products</th><th>Granted</th><th></th></tr>
                                </thead>
                                <tbody>
                                @forelse ($user->productPartnerAssignments as $assignment)
                                    <tr>
                                        <td class="fw-semibold">{{ $assignment->label() }}</td>
                                        <td class="text-muted small">
                                            @if ($assignment->grantedBy) by {{ $assignment->grantedBy->name }} @endif
                                            &middot; {{ $assignment->created_at?->format('d M Y') }}
                                        </td>
                                        <td class="text-end">
                                            <form method="POST" class="d-inline"
                                                  action="{{ route('admin.users.product-partner.remove', [$user, $assignment]) }}"
                                                  onsubmit="return confirm('Remove {{ $assignment->label() }} from {{ $user->name }}?');">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-outline-danger btn-xs" type="submit">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-muted small">
                                        Nothing linked yet — this account can sign in but sees only a holding page.
                                    </td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>

                        <form method="POST" action="{{ route('admin.users.product-partner.add', $user) }}"
                              class="d-flex flex-wrap gap-2 align-items-center">
                            @csrf
                            <select name="vendor" class="form-select form-select-sm" style="width:auto;" required>
                                @foreach (\App\Support\Vendors::all() as $slug => $config)
                                    <option value="{{ $slug }}">{{ $config['name'] ?? $slug }}</option>
                                @endforeach
                            </select>
                            <select name="product_key" class="form-select form-select-sm" style="width:auto;" required>
                                <option value="*">All products</option>
                                @foreach (\App\Support\Vendors::all() as $slug => $config)
                                    @foreach ((array) ($config['products'] ?? []) as $key => $product)
                                        <option value="{{ $key }}">{{ $config['name'] ?? $slug }} — {{ $product['name'] ?? $key }}</option>
                                    @endforeach
                                @endforeach
                            </select>
                            <button class="btn btn-primary btn-sm" type="submit">Link</button>
                            <span class="text-muted small">
                                They see sales, pipeline counts and the settlement account for what is linked here.
                            </span>
                        </form>

                        {{-- Selling, as opposed to watching. One button rather
                             than "add the business line and remember to tick
                             primary" — getting that wrong asks a vendor's
                             employee for a card. --}}
                        <hr class="my-3">
                        @if ($user->canUseMemberArea())
                            <p class="mb-2">
                                <span class="badge bg-success">Can sell</span>
                                This account also has the member area:
                                <strong>{{ $user->opportunity()->name() }}</strong>,
                                referral code <code>{{ $user->referral_code }}</code>.
                                They earn commission like any partner.
                            </p>
                            <form method="POST"
                                  action="{{ route('admin.users.product-partner.member-access.remove', $user) }}"
                                  onsubmit="return confirm('Remove the member area from {{ $user->name }}? They keep the partner portal.');">
                                @csrf @method('DELETE')
                                <button class="btn btn-outline-danger btn-sm" type="submit">Remove member access</button>
                            </form>
                        @else
                            <p class="text-muted small mb-2">
                                Portal only — this account can see the numbers but has no back office and cannot sell.
                                Giving it member access puts them on the business line for their vendor, with no card
                                asked for, and gives them a referral code that earns commission.
                            </p>
                            <form method="POST" action="{{ route('admin.users.product-partner.member-access.add', $user) }}">
                                @csrf
                                <button class="btn btn-primary btn-sm" type="submit">Give member access (let them sell)</button>
                            </form>
                        @endif
                    @endif
                </div>
            </div>

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
