{{-- The proposal itself, shared by the portal, the admin preview and the
     vendor's review page. Claims are rendered from the library, word for
     word, never from the draft's text. $docLink(document) gives a download
     URL, or null where downloads are not offered. Inside a microsite,
     $embedded titles it as a section and $hidePricing leaves pricing out
     for people without pricing access. --}}
@php
    $c = $proposal->content ?? [];
    $md = fn (?string $text) => \Illuminate\Support\Str::markdown((string) $text, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
@endphp

<section id="top">
    <span class="eyebrow">{{ $eyebrow ?? 'Prepared for '.$prospect->company_name }}</span>
    <{{ empty($embedded) ? 'h1' : 'h2' }} style="margin: 10px 0 16px;">{{ $c['headline'] ?? '' ?: $offering->name.' for '.$prospect->company_name }}</{{ empty($embedded) ? 'h1' : 'h2' }}>
    @if(!empty($c['intro']))<div class="lede md">{!! $md($c['intro']) !!}</div>@endif
    <p class="muted" style="margin-top: 14px;">Presented by Quantum 3 Solution · {{ $offering->name }} is made and sold by {{ $offering->vendor_name }} · Version {{ $proposal->version }}@if($proposal->published_at), {{ $proposal->published_at->format('F j, Y') }}@endif</p>
</section>

@if(!empty($c['situation']))
<section id="situation">
    <div class="sheet">
        <h2>What we understand</h2>
        <div class="md">{!! $md($c['situation']) !!}</div>
        @isset($correctionForm){!! $correctionForm !!}@endisset
    </div>
</section>
@endif

@if(!empty($c['plan']))
<section id="plan">
    <div class="sheet">
        <h2>What we recommend</h2>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Where</th><th>What</th><th>How many</th><th>Why</th></tr></thead>
                <tbody>
                @foreach($c['plan'] as $row)
                    <tr>
                        <td>{{ $row['site'] ?? '' }}</td>
                        <td>{{ $row['product'] ?? '' }}</td>
                        <td class="qty">{{ $row['quantity'] ?? '' }}</td>
                        <td class="md">{!! $md($row['rationale'] ?? '') !!}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="muted" style="margin-top: 12px;">Quantities are estimates from what we know so far. A site survey or formal quote confirms them.</p>
    </div>
</section>
@endif

@if(!empty($c['how_it_works']) || !empty($c['benefits']))
<section id="how">
    <div class="sheet stack">
        <h2 style="margin-bottom: 0;">How it works for you</h2>
        @if(!empty($c['how_it_works']))<div class="md">{!! $md($c['how_it_works']) !!}</div>@endif
        @if(!empty($c['benefits']))
            <div class="benefits">
                @foreach($c['benefits'] as $b)
                    <div class="benefit"><h3 style="margin-bottom: 6px;">{{ $b['title'] ?? '' }}</h3><div class="md">{!! $md($b['text'] ?? '') !!}</div></div>
                @endforeach
            </div>
        @endif
    </div>
</section>
@endif

@if($claims->isNotEmpty())
<section id="evidence">
    <div class="sheet">
        <h2>What the testing shows</h2>
        <p class="muted">In {{ $offering->vendor_name }}'s own words, from their published material.</p>
        <div class="claims" style="margin-top: 18px;">
            @foreach($claims as $claim)
                <div class="claim">
                    <blockquote>{{ $claim->quote }}</blockquote>
                    <div class="fn">
                        @if($claim->footnote){{ $claim->footnote }} · @endif
                        Source: {{ $claim->document->title ?? $offering->vendor_name }}
                    </div>
                </div>
            @endforeach
        </div>
        @php $links = $documents->map(fn ($d) => ['doc' => $d, 'url' => $docLink($d)])->filter(fn ($l) => $l['url']); @endphp
        @if($links->isNotEmpty())
            <h3 style="margin-top: 26px;">Documents</h3>
            <ul>@foreach($links as $l)<li><a href="{{ $l['url'] }}">{{ $l['doc']->title }}</a></li>@endforeach</ul>
        @endif
    </div>
</section>
@endif

@if(!empty($c['pricing']) && empty($hidePricing))
<section id="pricing">
    <div class="sheet">
        <h2>Investment</h2>
        <div class="md">{!! $md($c['pricing']) !!}</div>
        <p class="note" style="margin-top: 12px;">These figures are estimates. {{ $offering->vendor_name }} confirms the final price in a formal quote.</p>
    </div>
</section>
@endif
