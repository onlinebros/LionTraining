{{-- One section of a microsite, drawn by its layout. Claims are always
     rendered from the library by id, word for word. --}}
@php
    $c = $section->c();
    $offering = $section->offering();
    $claims = $section->claims();
    $tags = array_filter([
        $preview && ! $section->isPublished() ? 'Draft: not on the site yet' : null,
        $section->pricing_only ? 'Only people with pricing access see this' : null,
    ]);
    $lead = $c['lead'] ?? null;
    $by = $offering?->vendor_name ?? 'the maker';
    $quote = fn ($claim, int $i, bool $feature = false) => view('microsite._quote', ['claim' => $claim, 'i' => $i, 'feature' => $feature, 'offering' => $offering])->render();
@endphp

<section class="sec" id="s-{{ $section->id }}" data-track="{{ $section->id }}" @if($preview) data-edit="{{ $section->id }}" @endif>
    <div class="wrap">
        @foreach($tags as $tag)<span class="tag">{{ $tag }}</span>@endforeach

        @if($section->kind === 'proposal')
            <div class="sec-head rv"><span class="eyebrow">Proposal</span><h2>{{ $section->title }}</h2></div>
            <div class="embedded rv">
                @if($section->opportunity?->publishedProposal)
                    @include('portal._proposal', [
                        'prospect' => $section->opportunity,
                        'offering' => $section->opportunity->offering,
                        'proposal' => $section->opportunity->publishedProposal,
                        'claims' => $section->opportunity->publishedProposal->claims(),
                        'documents' => $section->opportunity->offering->documents()->where('audience', 'shareable')->whereNotNull('file_path')->get(),
                        'docLink' => fn ($d) => $live ? route('microsite.download', [$ref, $d]) : null,
                        'embedded' => true,
                        'eyebrow' => 'Version '.$section->opportunity->publishedProposal->version,
                        'hidePricing' => ! $canSeePricing,
                    ])
                @else
                    <p class="muted">Your proposal is being prepared.</p>
                @endif
            </div>

        @elseif($section->kind === 'documents')
            <div class="sec-head rv"><span class="eyebrow">Library</span><h2>{{ $section->title }}</h2></div>
            @php $docs = $offering ? $offering->documents()->where('audience', 'shareable')->whereNotNull('file_path')->get() : collect(); @endphp
            @if($docs->isEmpty())
                <p class="muted">Documents will appear here.</p>
            @else
                <div class="docs">
                    @foreach($docs as $doc)
                        @if($live)<a class="doc rv" style="--i: {{ $loop->index }}" href="{{ route('microsite.download', [$ref, $doc]) }}">@else<div class="doc rv" style="--i: {{ $loop->index }}">@endif
                            <span class="ico">@include('microsite._icon', ['name' => 'doc'])</span>
                            <span><strong>{{ $doc->title }}</strong><br><span class="muted">{{ $doc->kindLabel() }}</span></span>
                        @if($live)</a>@else</div>@endif
                    @endforeach
                </div>
                <p class="attrib">Published by {{ $by }}.</p>
            @endif

        @elseif($section->layout === 'hero')
            {{-- A hero placed later in the site: a large statement band. --}}
            <div class="sec-head rv" style="max-width: 900px;">
                @if(!empty($c['eyebrow']))<span class="eyebrow">{{ $c['eyebrow'] }}</span>@endif
                <h2 style="font-size: clamp(36px, 6vw, 72px);">{{ $c['headline'] ?? $section->title }}</h2>
                @if($lead)<p class="lead">{{ $lead }}</p>@endif
            </div>

        @else
            <div class="sec-head rv">
                <span class="eyebrow">{{ $section->kindLabel() }}</span>
                <h2>{{ $section->title }}</h2>
                @if($lead)<p class="lead">{{ $lead }}</p>@endif
            </div>

            @switch($section->layout)
                @case('cards')
                    <div class="cards">
                        @foreach($c['items'] ?? [] as $item)
                            <div class="card rv" style="--i: {{ $loop->index }}">
                                <span class="ico">@include('microsite._icon', ['name' => $item['icon']])</span>
                                <h3>{{ $item['title'] }}</h3>
                                <p>{{ $item['text'] }}</p>
                            </div>
                        @endforeach
                    </div>
                    @break

                @case('timeline')
                    <div class="timeline">
                        @foreach($c['steps'] ?? [] as $step)
                            <div class="step rv">
                                <span class="dot">{{ $loop->iteration }}</span>
                                @if(($step['when'] ?? '') !== '')<div class="when">{{ $step['when'] }}</div>@endif
                                <h3>{{ $step['title'] }}</h3>
                                @if(($step['text'] ?? '') !== '')<p>{{ $step['text'] }}</p>@endif
                            </div>
                        @endforeach
                    </div>
                    @break

                @case('proof')
                    @if($claims->isEmpty())
                        <p class="muted">The results we can share with you will appear here.</p>
                    @else
                        <div class="proof">
                            @foreach($claims as $claim)
                                {!! $quote($claim, $loop->index, $loop->first) !!}
                            @endforeach
                        </div>
                        <p class="attrib">In {{ $by }}'s own words, from their published material.</p>
                    @endif
                    @break

                @case('faq')
                    <div class="faq">
                        @foreach($c['items'] ?? [] as $item)
                            <details class="rv" style="--i: {{ $loop->index }}" @if($loop->first) open @endif>
                                <summary>{{ $item['q'] }}</summary>
                                <div class="a">{{ $item['a'] }}</div>
                            </details>
                        @endforeach
                    </div>
                    @break

                @case('plan')
                    @php
                        $steps = $c['steps'] ?? [];
                        $done = collect($steps)->where('done', true)->count();
                        $pct = count($steps) ? $done / count($steps) : 0;
                        $canTick = $live && $member->canComment();
                    @endphp
                    <div class="plan">
                        <div class="ring rv" data-ring>
                            <svg viewBox="0 0 180 180" aria-hidden="true">
                                <defs><linearGradient id="ringGrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="var(--accent)"/><stop offset="1" stop-color="var(--gold)"/></linearGradient></defs>
                                <circle class="track" cx="90" cy="90" r="80"/>
                                <circle class="bar" cx="90" cy="90" r="80" stroke-dasharray="502.65" stroke-dashoffset="{{ 502.65 * (1 - $pct) }}" data-pct="{{ $pct }}"/>
                            </svg>
                            <div class="label"><b data-done>{{ $done }}/{{ count($steps) }}</b><span>steps done</span></div>
                        </div>
                        <div class="steps">
                            @foreach($steps as $i => $step)
                                <div class="pstep rv {{ $step['done'] ? 'done' : '' }}" style="--i: {{ $loop->index }}">
                                    @if($canTick)
                                        <form method="POST" action="{{ route('microsite.plan', [$ref, $section, $i]) }}" data-tick>
                                            @csrf<input type="hidden" name="done" value="{{ $step['done'] ? 0 : 1 }}">
                                            <button class="tick" type="submit" aria-pressed="{{ $step['done'] ? 'true' : 'false' }}" aria-label="{{ $step['done'] ? 'Reopen' : 'Tick off' }}: {{ $step['title'] }}">@include('microsite._icon', ['name' => 'tick'])</button>
                                        </form>
                                    @else
                                        <span class="tick">@include('microsite._icon', ['name' => 'tick'])</span>
                                    @endif
                                    <span class="t">{{ $step['title'] }}</span>
                                    <span class="owner {{ $step['owner'] }}">{{ \App\Models\MicrositeSection::OWNERS[$step['owner']] ?? '' }}</span>
                                    @if(($step['when'] ?? '') !== '')<span class="w">{{ $step['when'] }}</span>@endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                    @break

                @case('cta')
                    @php
                        $url = ($c['button_url'] ?? '') ?: ($account->owner?->email ? 'mailto:'.$account->owner->email.'?subject='.rawurlencode($account->siteTitle()) : null);
                    @endphp
                    <div class="cta rv">
                        <h2>{{ ($c['headline'] ?? '') ?: $section->title }}</h2>
                        @if(!empty($c['text']))<p>{{ $c['text'] }}</p>@endif
                        @if($url)<a class="btn" href="{{ $url }}" @if(str_starts_with($url, 'https://')) target="_blank" rel="noopener" @endif>{{ ($c['button_label'] ?? '') ?: 'Talk to us' }} @include('microsite._icon', ['name' => 'arrow'])</a>@endif
                    </div>
                    @break

                @default
                    @if($section->body)<div class="prose rv">{!! $md($section->body) !!}</div>@endif
            @endswitch

            @if($section->layout !== 'proof' && $claims->isNotEmpty())
                <div class="backing">
                    @foreach($claims as $claim){!! $quote($claim, $loop->index) !!}@endforeach
                </div>
                <p class="attrib">In {{ $by }}'s own words, from their published material.</p>
            @endif
        @endif

        @if($live && $member->canComment())
            @php $mine = $section->comments->where('microsite_member_id', $member->id); @endphp
            <div class="feedback">
                @foreach($mine as $comment)
                    <div class="note-box"><strong>You wrote:</strong> {{ $comment->body }}<div class="muted" style="margin-top: 6px;">{{ $comment->reply ? 'Our reply: '.$comment->reply : 'We have it, and will reply by email.' }}</div></div>
                @endforeach
                <details style="margin-top: 14px;">
                    <summary>Feedback on this section</summary>
                    <form method="POST" action="{{ route('microsite.comment', [$ref, $section]) }}" class="stack" style="margin-top: 14px;">
                        @csrf
                        <div>
                            <label for="c-{{ $section->id }}">What should we change or add?</label>
                            <textarea id="c-{{ $section->id }}" name="body" rows="3" maxlength="5000" required></textarea>
                        </div>
                        <div><button class="btn" type="submit">Send feedback</button></div>
                    </form>
                </details>
            </div>
        @endif
    </div>
</section>
