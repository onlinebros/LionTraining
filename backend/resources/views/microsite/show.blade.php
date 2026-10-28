@extends('layouts.portal')

@section('title', $account->siteTitle())

@php
    $live = ! $preview && $member !== null;
    $ref = $account->site_ref;
    $md = fn (?string $text) => \Illuminate\Support\Str::markdown((string) $text, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    $vendors = $sections->map(fn ($s) => $s->opportunity?->offering)->filter()->unique('id');
@endphp

@include('microsite._brand')

@section('band-right')
    @if($preview)
        <small>Preview: drafts are shown and marked. Forms are switched off.</small>
    @elseif($live)
        <form method="POST" action="{{ route('microsite.sign-out', $ref) }}">
            @csrf
            <small>Signed in as {{ $member->email }} · <button type="submit" style="background:none;border:0;color:inherit;text-decoration:underline;font:inherit;cursor:pointer;padding:0;">Sign out</button></small>
        </form>
    @endif
@endsection

@section('content')
@if($sections->count() > 1)
<nav class="nav" aria-label="Sections">
    <div class="wrap">
        @foreach($sections as $section)<a href="#s-{{ $section->id }}">{{ $section->title }}</a>@endforeach
        @if($live)<a href="#colleagues">Colleagues</a>@endif
    </div>
</nav>
@endif

<main class="wrap">
    @if(session('success'))<div class="flash ok" role="status">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="flash err" role="alert">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="flash err" role="alert">{{ $errors->first() }}</div>@endif

    <section>
        <span class="eyebrow">Prepared for {{ $account->name }}</span>
        <h1 style="margin: 10px 0 16px;">{{ $account->siteTitle() }}</h1>
        @if($account->site_intro)<p class="lede">{{ $account->site_intro }}</p>@endif
    </section>

    @forelse($sections as $section)
        @php
            $opportunity = $section->opportunity;
            $offering = $opportunity?->offering;
            $tags = array_filter([
                $preview && ! $section->isPublished() ? 'Draft: not on the site yet' : null,
                $section->pricing_only ? 'Only people with pricing access see this' : null,
            ]);
        @endphp

        @if($section->kind === 'proposal')
            <div id="s-{{ $section->id }}" style="scroll-margin-top: 70px;">
                @foreach($tags as $tag)<span class="tag">{{ $tag }}</span> @endforeach
                @if($opportunity?->publishedProposal)
                    @include('portal._proposal', [
                        'prospect' => $opportunity,
                        'offering' => $offering,
                        'proposal' => $opportunity->publishedProposal,
                        'claims' => $opportunity->publishedProposal->claims(),
                        'documents' => $offering->documents()->where('audience', 'shareable')->whereNotNull('file_path')->get(),
                        'docLink' => fn ($d) => $live ? route('microsite.download', [$ref, $d]) : null,
                        'embedded' => true,
                        'eyebrow' => $section->title,
                        'hidePricing' => ! $canSeePricing,
                    ])
                @else
                    <section><div class="sheet"><h2>{{ $section->title }}</h2><p class="muted">Your proposal is being prepared.</p></div></section>
                @endif
            </div>
            @continue
        @endif

        <section id="s-{{ $section->id }}">
            <div class="sheet">
                @foreach($tags as $tag)<span class="tag" style="margin-bottom: 10px;">{{ $tag }}</span> @endforeach
                <h2>{{ $section->title }}</h2>

                @if($section->kind === 'documents')
                    @php $docs = $offering ? $offering->documents()->where('audience', 'shareable')->whereNotNull('file_path')->get() : collect(); @endphp
                    @if($docs->isEmpty())
                        <p class="muted">Documents will appear here.</p>
                    @else
                        <ul>
                            @foreach($docs as $doc)
                                <li>@if($live)<a href="{{ route('microsite.download', [$ref, $doc]) }}">{{ $doc->title }}</a>@else{{ $doc->title }}@endif</li>
                            @endforeach
                        </ul>
                        <p class="muted">Published by {{ $offering->vendor_name }}.</p>
                    @endif
                @else
                    @if($section->body)<div class="md">{!! $md($section->body) !!}</div>@endif
                    @php $claims = $section->claims(); @endphp
                    @if($claims->isNotEmpty())
                        <p class="muted" style="margin-top: 18px;">In {{ $offering?->vendor_name ?? 'the maker' }}'s own words, from their published material.</p>
                        <div class="claims" style="margin-top: 12px;">
                            @foreach($claims as $claim)
                                <div class="claim">
                                    <blockquote>{{ $claim->quote }}</blockquote>
                                    <div class="fn">@if($claim->footnote){{ $claim->footnote }} · @endif Source: {{ $claim->document->title ?? $offering?->vendor_name }}</div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endif

                @if($live && $member->canComment())
                    @php $mine = $section->comments->where('microsite_member_id', $member->id); @endphp
                    @foreach($mine as $comment)
                        <div class="qa" style="margin-top: 16px;">
                            <div class="q">You wrote: <span style="font-weight: 400;">{{ $comment->body }}</span></div>
                            <div class="a {{ $comment->reply ? '' : 'muted' }}">{{ $comment->reply ? 'Our reply: '.$comment->reply : 'We have it, and will reply by email.' }}</div>
                        </div>
                    @endforeach
                    <details style="margin-top: 16px;">
                        <summary>Feedback on this section</summary>
                        <form method="POST" action="{{ route('microsite.comment', [$ref, $section]) }}" class="stack" style="margin-top: 10px;">
                            @csrf
                            <div>
                                <label for="c-{{ $section->id }}">What should we change or add?</label>
                                <textarea id="c-{{ $section->id }}" name="body" rows="3" maxlength="5000" required></textarea>
                            </div>
                            <div><button class="btn" type="submit">Send feedback</button></div>
                        </form>
                    </details>
                @endif
            </div>
        </section>
    @empty
        <section><div class="sheet"><p class="muted" style="margin: 0;">We are preparing this site for you now. Come back soon.</p></div></section>
    @endforelse

    @if($progress->isNotEmpty())
        <section>
            <div class="sheet">
                <h2>Still being prepared</h2>
                <p class="muted">We are working on these for you, and will add them here.</p>
                <ul>@foreach($progress as $title)<li>{{ $title }}</li>@endforeach</ul>
            </div>
        </section>
    @endif

    @if($live)
        <section id="colleagues">
            <div class="sheet">
                <h2>Add a colleague</h2>
                <p>Please do not forward your link: it signs in as you. Tell us who else should see this, and we will send them their own once we have approved it.</p>
                <form method="POST" action="{{ route('microsite.request-access', $ref) }}" class="stack">
                    @csrf
                    <div class="grid2">
                        <div><label for="rq-name">Their name</label><input id="rq-name" type="text" name="name" maxlength="255" value="{{ old('name') }}"></div>
                        <div><label for="rq-email">Their email</label><input id="rq-email" type="email" name="email" maxlength="255" required value="{{ old('email') }}"></div>
                    </div>
                    <div><label for="rq-note">Their role, or why they should see it <span class="muted">(optional)</span></label><input id="rq-note" type="text" name="note" maxlength="2000" value="{{ old('note') }}"></div>
                    <div><button class="btn" type="submit">Ask for access</button></div>
                </form>
            </div>
        </section>
    @endif
</main>

<footer class="wrap">
    Quantum 3 Solution is an independent referral partner.
    @foreach($vendors as $offering){{ $offering->name }} is made and sold by {{ $offering->vendor_name }}, which provides the formal quote and is the seller of record. @endforeach
    @if($vendors->isNotEmpty())Statements about product performance are the maker's, quoted from their material.@endif
    @if($account->logo_path){{ $account->name }}'s name and logo are shown for them only, on this private site.@endif
</footer>
@endsection
