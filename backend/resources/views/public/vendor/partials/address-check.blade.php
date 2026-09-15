{{--
    The delivery address check, shown under the address form once there is an
    address. One of five states: verified, a correction to choose, something to
    confirm, confirmed as entered, or undeliverable.

    The buyer always decides. Nothing here changes an address the buyer did not
    choose, apart from FedEx's formatting of an address it confirmed.
--}}

@once
@push('styles')
<style>
    .q3-storefront .q3-sf-check { align-items: flex-start; color: var(--q3-text-body); cursor: pointer; display: flex; font-size: 0.84rem; gap: 10px; line-height: 1.5; margin: 12px 0; }
    .q3-storefront .q3-sf-check input[type="checkbox"] { accent-color: var(--q3-gold); appearance: auto; height: 18px; margin: 2px 0 0; min-width: 18px; padding: 0; width: 18px; }
    .q3-storefront .q3-sf-addr { color: var(--q3-text); margin: 8px 0 0; }
    .q3-storefront .q3-sf-addr--muted { color: var(--q3-text-muted); font-size: 0.8rem; margin-top: 4px; }
    .q3-storefront .q3-sf-addr-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
</style>
@endpush
@endonce

@php
    $status    = $lead->address_status;
    $confirmed = $lead->address_confirmed_at !== null;
    $oneLine   = static fn (array $a): string => collect([
        trim(($a['address_line1'] ?? '').' '.($a['address_line2'] ?? '')),
        $a['city'] ?? null,
        trim(($a['state'] ?? '').' '.($a['postal_code'] ?? '')),
    ])->filter()->implode(', ');
    $entered   = $lead->only(['address_line1', 'address_line2', 'city', 'state', 'postal_code']);
    $delivery  = match ($lead->address_classification) {
        'RESIDENTIAL' => ' · residential delivery',
        'BUSINESS'    => ' · business delivery',
        default       => '',
    };
    $choiceUrl = route('vendor.order.address.choice', $lead->public_ref);
@endphp

@if ($status === 'rejected')
    <div class="q3-sf-alert" style="margin-top:16px;">{{ $lead->address_status_reason }}</div>

@elseif ($status === 'verified')
    <p class="q3-sf-formnote">✓ Address verified by FedEx{{ $delivery }}.</p>

@elseif ($confirmed)
    <p class="q3-sf-formnote">
        You confirmed this delivery address. {{ $vendor['name'] }} will double-check it before shipping.
    </p>

@elseif ($status === 'suggested')
    <div class="q3-sf-warn" style="margin-top:16px;" role="status">
        <strong>FedEx suggests a correction.</strong>
        <div class="q3-sf-addr">{{ $oneLine((array) $lead->address_suggestion) }}</div>
        <div class="q3-sf-addr q3-sf-addr--muted">You entered: {{ $oneLine($entered) }}</div>

        <form method="POST" action="{{ $choiceUrl }}" class="q3-sf-addr-actions">
            @csrf
            <button type="submit" name="choice" value="suggested" class="q3-sf-btn q3-sf-btn--gold">Use suggested address</button>
            <button type="submit" name="choice" value="entered" class="q3-sf-btn q3-sf-btn--ghost">Keep my address</button>
        </form>
    </div>

@elseif (in_array($status, ['unverified', 'unavailable'], true))
    <div class="q3-sf-warn" style="margin-top:16px;" role="status">
        <strong>{{ $status === 'unavailable' ? "We couldn't check this address automatically." : "FedEx couldn't confirm this address." }}</strong>
        @if ($status === 'unverified') {{ $lead->address_status_reason }} @endif
        Correct it above, or confirm it is right to continue.

        <form method="POST" action="{{ $choiceUrl }}">
            @csrf
            <input type="hidden" name="choice" value="entered">
            <label class="q3-sf-check">
                <input type="checkbox" name="confirm_address" value="1" required>
                <span>{{ $oneLine($entered) }} is correct. Ship to this address as entered.</span>
            </label>
            <button type="submit" class="q3-sf-btn q3-sf-btn--ghost q3-sf-btn--block">Continue with this address</button>
        </form>
    </div>
@endif
