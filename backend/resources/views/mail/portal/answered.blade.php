<x-mail::message>
# An answer to your question

You asked:

> {{ $submission->body }}

{{ $submission->answer }}

<x-mail::button :url="$url">
Back to your proposal
</x-mail::button>

Quantum 3 Solution
</x-mail::message>
