<x-mail::message>
# Your proposal is ready

Hello{{ $invite->name ? ' '.$invite->name : '' }},

We have put together a proposal for {{ $prospect->company_name }} on {{ $offering->name }}. It is on a private page made for you: what we understood about your business, what we would recommend and why, and what the testing shows.

You can add details about your buildings there, send photos or plans, and ask questions. Anything you send comes straight to us.

<x-mail::button :url="$url">
Open your proposal
</x-mail::button>

This link is for you and works for {{ $days }} days. If it stops working, open the page again and ask for a new one by email.

Quantum 3 Solution<br>
Presenting {{ $offering->name }} by {{ $offering->vendor_name }}
</x-mail::message>
