{{-- One card, step or question in the builder. $i is an index, or __I__ in the template. --}}
@php $n = "content[{$key}][{$i}]"; @endphp
<div class="msb-row">
    <button type="button" class="rm" aria-label="Remove">×</button>
    @switch($layout)
        @case('cards')
            <div class="d-flex gap-2 mb-1">
                <select name="{{ $n }}[icon]" class="form-select form-select-sm" style="max-width: 7.5rem;" aria-label="Icon">
                    @foreach(\App\Models\MicrositeSection::ICONS as $icon)<option value="{{ $icon }}" @selected(($row['icon'] ?? 'spark') === $icon)>{{ $icon }}</option>@endforeach
                </select>
                <input type="text" name="{{ $n }}[title]" maxlength="90" class="form-control form-control-sm" placeholder="Title" value="{{ $row['title'] ?? '' }}">
            </div>
            <textarea name="{{ $n }}[text]" rows="2" maxlength="400" class="form-control form-control-sm" placeholder="Text">{{ $row['text'] ?? '' }}</textarea>
            @break
        @case('timeline')
            <div class="d-flex gap-2 mb-1">
                <input type="text" name="{{ $n }}[title]" maxlength="90" class="form-control form-control-sm" placeholder="Step" value="{{ $row['title'] ?? '' }}">
                <input type="text" name="{{ $n }}[when]" maxlength="40" class="form-control form-control-sm" style="max-width: 8rem;" placeholder="When" value="{{ $row['when'] ?? '' }}">
            </div>
            <textarea name="{{ $n }}[text]" rows="2" maxlength="400" class="form-control form-control-sm" placeholder="What happens">{{ $row['text'] ?? '' }}</textarea>
            @break
        @case('faq')
            <input type="text" name="{{ $n }}[q]" maxlength="200" class="form-control form-control-sm mb-1" placeholder="Question" value="{{ $row['q'] ?? '' }}">
            <textarea name="{{ $n }}[a]" rows="3" maxlength="1200" class="form-control form-control-sm" placeholder="Answer">{{ $row['a'] ?? '' }}</textarea>
            @break
        @case('plan')
            <input type="text" name="{{ $n }}[title]" maxlength="120" class="form-control form-control-sm mb-1" placeholder="Step" value="{{ $row['title'] ?? '' }}">
            <div class="d-flex gap-2 align-items-center">
                <select name="{{ $n }}[owner]" class="form-select form-select-sm" style="max-width: 8rem;" aria-label="Who">
                    @foreach(\App\Models\MicrositeSection::OWNERS as $k => $label)<option value="{{ $k }}" @selected(($row['owner'] ?? 'both') === $k)>{{ $label }}</option>@endforeach
                </select>
                <input type="text" name="{{ $n }}[when]" maxlength="40" class="form-control form-control-sm" placeholder="When" value="{{ $row['when'] ?? '' }}">
                <input type="hidden" name="{{ $n }}[done]" value="0">
                <div class="form-check mb-0 text-nowrap"><input class="form-check-input" type="checkbox" name="{{ $n }}[done]" value="1" id="d-{{ $n }}" @checked($row['done'] ?? false)><label class="form-check-label small" for="d-{{ $n }}">Done</label></div>
            </div>
            @break
    @endswitch
</div>
