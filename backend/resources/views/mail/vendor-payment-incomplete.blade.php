<x-mail::message>
# {{ $guide->title }}

**{{ $guide->intro }}**

Order reference: **{{ $lead->public_ref }}**<br>
{{ max(1, (int) $lead->quantity) }} × {{ $lead->productName() }}
@if ($guide->option)
<br>Payment option you chose: **{{ $guide->option }}**
@endif

## {{ $guide->stepsHeading }}

@foreach ($guide->steps as $step)
{{ $loop->iteration }}. {{ $step }}
@endforeach

@if ($guide->canRetry)
<x-mail::button :url="$orderUrl">
Return to checkout
</x-mail::button>
@endif

This email is not an order confirmation. An order is placed only when {{ $seller }} has received payment in full.

{{ $seller }} is the seller of record — they take payment, ship, and provide the warranty. Questions about payment or delivery: {{ $support }}, quoting {{ $lead->public_ref }}.
</x-mail::message>
