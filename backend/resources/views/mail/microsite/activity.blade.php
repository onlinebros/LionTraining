<x-mail::message>
# {{ $headline }}

@foreach($lines as $line)
{{ $line }}

@endforeach
<x-mail::button :url="$url">
Open the microsite builder
</x-mail::button>
</x-mail::message>
