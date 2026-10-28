<x-mail::message>
# {{ $prospect->company_name }}: {{ $submission->kindLabel() }}

From {{ $from ?: 'the prospect' }}, through their portal.

@if($submission->body)
> {{ $submission->body }}
@endif

@foreach($submission->fields ?? [] as $label => $value)
**{{ $label }}:** {{ $value }}<br>
@endforeach

@if(!empty($submission->files))
{{ count($submission->files) }} file(s) attached. Open the workspace to download them.
@endif

@if($submission->isWaitingForAnswer())
The assistant could not answer this from the approved material, so it is waiting for you.
@endif

<x-mail::button :url="$url">
Open the workspace
</x-mail::button>
</x-mail::message>
