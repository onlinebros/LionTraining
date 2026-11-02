{{-- An approved claim, word for word, with its footnote and source. --}}
<figure class="quote rv {{ $feature ? 'feature' : '' }}" style="margin: 0; --i: {{ $i }};">
    <blockquote>{!! \App\Models\MicrositeSection::markNumbers($claim->quote) !!}</blockquote>
    <figcaption class="fn">@if($claim->footnote){{ $claim->footnote }} · @endif Source: {{ $claim->document->title ?? $offering?->vendor_name }}</figcaption>
</figure>
