<x-mail::message>
# New order: {{ $lead->public_ref }}

A customer has paid for the order below. Payment was taken on your Stripe account{{ $lead->provider_payment_intent_id ? ' as '.$lead->provider_payment_intent_id : '' }}.

## Order

<x-mail::table>
| | |
|:--|:--|
| Reference | {{ $lead->public_ref }} |
| Date | {{ $lead->converted_at?->timezone('America/New_York')->format('M j, Y g:i A T') }} |
| Product | {{ $lead->productName() }} |
| Quantity | {{ max(1, (int) $lead->quantity) }} |
@if ($lead->shipping_service)
| Ship via | {{ $lead->shipping_service }} |
@endif
@if ($lead->subtotal_amount !== null)
| Product subtotal | {{ $money($lead->subtotal_amount) }} |
| Shipping | {{ $money($lead->shipping_amount) }} |
| Handling | {{ $money($lead->handling_amount) }} |
| Sales tax | {{ $money($lead->tax_amount) }} |
@endif
| **Total charged** | **{{ $money($lead->amount_total) }}** |
</x-mail::table>

## Customer

{{ $lead->fullName() }}@if ($lead->company)<br>{{ $lead->company }}@endif<br>
{{ $lead->email }}@if ($lead->phone)<br>{{ $lead->phone }}@endif


## Ship to

@if ($lead->address_line1)
{{ $lead->fullName() }}<br>
@if ($lead->company){{ $lead->company }}<br>@endif
{{ $lead->address_line1 }}<br>
@if ($lead->address_line2){{ $lead->address_line2 }}<br>@endif
{{ $lead->city }}, {{ $lead->state }} {{ $lead->postal_code }}<br>
{{ $lead->country }}
@else
No delivery address was captured with this order. Please confirm it with the customer.
@endif

@if ($lead->notes)
## Customer notes

{{ $lead->notes }}
@endif

Please quote {{ $lead->public_ref }} in anything about this order.

{{ config('mail.from.name') }}
</x-mail::message>
