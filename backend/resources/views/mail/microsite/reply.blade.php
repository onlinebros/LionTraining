<x-mail::message>
# We replied to your feedback

On "{{ $section->title }}", you wrote:

> {{ $comment->body }}

{{ $comment->reply }}

<x-mail::button :url="$url">
Open the site
</x-mail::button>

Quantum 3 Solution
</x-mail::message>
