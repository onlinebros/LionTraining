{{-- One portal form: optional labelled fields, a free-text box, optional uploads. --}}
@php $id = 'pf-'.$kind; @endphp
@if($summary)<details style="margin-top: 16px;"><summary>{{ $summary }}</summary>@endif
<form method="POST" action="{{ route('portal.submit', $ref) }}" enctype="multipart/form-data" class="stack" style="margin-top: 14px;">
    @csrf
    <input type="hidden" name="kind" value="{{ $kind }}">
    @if($fields)
        <div class="grid2">
            @foreach($fields as $label => $hint)
                <div>
                    <label for="{{ $id }}-{{ $loop->index }}">{{ $label }}</label>
                    <input id="{{ $id }}-{{ $loop->index }}" type="text" name="fields[{{ $label }}]" maxlength="2000" placeholder="{{ $hint }}" value="{{ old('fields.'.$label) }}">
                </div>
            @endforeach
        </div>
    @endif
    <div>
        <label for="{{ $id }}-body">{{ $bodyLabel }}</label>
        <textarea id="{{ $id }}-body" name="body" rows="3" maxlength="10000">{{ old('kind') === $kind ? old('body') : '' }}</textarea>
    </div>
    @if($files)
        <div>
            <label for="{{ $id }}-files">Photos or plans <span class="muted" style="font-weight: 400;">(up to {{ config('proposal-studio.portal.upload_max_files') }} files)</span></label>
            <input id="{{ $id }}-files" type="file" name="files[]" multiple accept=".{{ implode(',.', config('proposal-studio.portal.upload_mimes')) }}">
        </div>
    @endif
    <div><button class="btn" type="submit">{{ $button }}</button></div>
</form>
@if($summary)</details>@endif
