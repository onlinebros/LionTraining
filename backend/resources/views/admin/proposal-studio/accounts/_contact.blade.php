{{-- Add a person to the account, or edit one ($contact null to add). --}}
@php $c = $contact; $k = $c?->id ?? 'new'; @endphp
<form method="POST" action="{{ $c ? route('admin.proposal-studio.accounts.contacts.update', [$account, $c]) : route('admin.proposal-studio.accounts.contacts.store', $account) }}" class="row g-2 mt-1">
    @csrf
    @if($c) @method('PUT') @endif
    <div class="col-sm-6"><input type="text" name="name" maxlength="255" required class="form-control form-control-sm" placeholder="Name" aria-label="Name" value="{{ $c?->name }}"></div>
    <div class="col-sm-6"><input type="text" name="title" maxlength="255" class="form-control form-control-sm" placeholder="Job title" aria-label="Job title" value="{{ $c?->title }}"></div>
    <div class="col-sm-6"><input type="email" name="email" maxlength="255" class="form-control form-control-sm" placeholder="Email" aria-label="Email" value="{{ $c?->email }}"></div>
    <div class="col-sm-6"><input type="text" name="phone" maxlength="40" class="form-control form-control-sm" placeholder="Phone" aria-label="Phone" value="{{ $c?->phone }}"></div>
    <div class="col-sm-6">
        <select name="role" class="form-select form-select-sm" aria-label="Their part in the decision">
            @foreach(\App\Models\BusinessContact::ROLES as $key => $label)
                <option value="{{ $key }}" @selected(($c?->role ?? 'other') === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-sm-6 d-flex align-items-center">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_primary" value="1" id="bc-primary-{{ $k }}" @checked($c?->is_primary)>
            <label class="form-check-label small" for="bc-primary-{{ $k }}">Main contact</label>
        </div>
    </div>
    <div class="col-12"><textarea name="notes" rows="2" maxlength="5000" class="form-control form-control-sm" placeholder="Notes: what they care about, how to reach them" aria-label="Notes">{{ $c?->notes }}</textarea></div>
    <div class="col-12"><button class="btn btn-primary btn-sm">{{ $c ? 'Save' : 'Add' }}</button></div>
</form>
