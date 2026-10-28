<x-mail::message>
# A private site for {{ $account->name }}

Hello{{ $member->name ? ' '.$member->name : '' }},

@if($requestedBy)
{{ $requestedBy }} asked us to give you access to the site we are building for {{ $account->name }}.
@else
We are building a private site for {{ $account->name }}: what we understand about your business, how we would approach it, and the material behind it.
@endif

We add to it as the work goes on, so it is worth coming back to. @if($member->role === 'contributor')You can leave feedback on any section, and it comes straight to us.@endif

<x-mail::button :url="$url">
Open the site
</x-mail::button>

This link is for you and works for {{ $days }} days. If it stops working, open the site again and ask for a new one by email. Please do not forward it; ask for a colleague to be added from the site instead.

Quantum 3 Solution
</x-mail::message>
