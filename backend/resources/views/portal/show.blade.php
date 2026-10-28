@extends('layouts.portal')

@section('title', $prospect->company_name)

@section('band-right')
    @if($preview)
        <small>Preview: this is what the prospect sees. Forms are switched off.</small>
    @elseif(isset($invite))
        <form method="POST" action="{{ route('portal.sign-out', $prospect->portal_ref) }}">
            @csrf
            <small>Signed in as {{ $invite->email }} · <button type="submit" style="background:none;border:0;color:#d4af37;font:inherit;cursor:pointer;padding:0;">Sign out</button></small>
        </form>
    @endif
@endsection

@php
    $live = ! $preview && isset($invite);
    $ref = $prospect->portal_ref;
    $docLink = fn ($d) => $live ? route('portal.download', [$ref, $d]) : null;
@endphp

@section('content')
<nav class="nav" aria-label="Sections">
    <div class="wrap">
        <a href="#situation">Your situation</a>
        <a href="#plan">Recommendation</a>
        <a href="#how">How it works</a>
        <a href="#evidence">Evidence</a>
        <a href="#questions">Questions</a>
        <a href="#next">Next steps</a>
    </div>
</nav>

<main class="wrap">
    @if(session('success'))<div class="flash ok" role="status">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="flash err" role="alert">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="flash err" role="alert">{{ $errors->first() }}</div>@endif

    @php
        $correctionForm = $live ? view('portal._form', ['ref' => $ref, 'kind' => 'correction', 'summary' => 'Something not right? Tell us', 'fields' => [], 'bodyLabel' => 'What should we know?', 'button' => 'Send correction', 'files' => false])->render() : null;
    @endphp
    @include('portal._proposal', ['correctionForm' => $correctionForm])

    <section id="details">
        <div class="sheet">
            <h2>Tell us more</h2>
            <p>The more we know about your space, the more exact the recommendation. Answer whatever you can; photos of your HVAC equipment or a floor plan help most.</p>
            @if($live)
                @include('portal._form', [
                    'ref' => $ref, 'kind' => 'details', 'summary' => null, 'button' => 'Send details', 'files' => true,
                    'bodyLabel' => 'Anything else we should know',
                    'fields' => [
                        'Locations' => 'How many locations, and where',
                        'Square footage' => 'Roughly, per location',
                        'HVAC' => 'Type of system, how many units or air handlers',
                        'People in the space' => 'Staff and visitors on a typical day',
                        'Main concern' => 'What prompted you to look at this',
                        'Timeline' => 'When you would like this in place',
                        'Who else decides' => 'Names or roles of anyone else involved',
                    ],
                ])
            @else
                <p class="muted">A short form with fields for locations, square footage, HVAC, occupancy, concerns, timeline and who else decides, plus photo and plan uploads.</p>
            @endif
        </div>
    </section>

    <section id="questions">
        <div class="sheet">
            <h2>Questions</h2>
            @if($live)
                <div class="stack" style="margin-bottom: 18px;">
                    @forelse($questions as $q)
                        <div class="qa">
                            <div class="q">{{ $q->body }}</div>
                            @if($q->answer)
                                <div class="a md">{!! \Illuminate\Support\Str::markdown($q->answer, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
                                <div class="muted">{{ $q->answered_by === 'assistant' ? 'Answered from '.$offering->vendor_name."'s approved material" : 'Answered by your Q3 contact' }}, {{ $q->answered_at?->diffForHumans() }}</div>
                            @else
                                <div class="a muted">We will answer this by email shortly.</div>
                            @endif
                        </div>
                    @empty
                        <p class="muted">Ask anything about the recommendation, how the system works or what installation involves.</p>
                    @endforelse
                </div>
                <form method="POST" action="{{ route('portal.ask', $ref) }}" class="stack">
                    @csrf
                    <div>
                        <label for="pq">Your question</label>
                        <textarea id="pq" name="question" rows="3" maxlength="3000" required>{{ old('question') }}</textarea>
                    </div>
                    <div><button class="btn" type="submit">Ask</button></div>
                </form>
            @else
                <p class="muted">Prospects ask questions here. {{ \App\Services\ProposalStudio\PortalAssistant::available() ? 'The assistant answers from approved material where it can, and passes the rest to you.' : 'Questions come to you to answer.' }}</p>
            @endif
        </div>
    </section>

    <section id="next">
        <div class="sheet">
            <h2>Next steps</h2>
            @if(!empty($proposal->content['next_steps']))
                <div class="md">{!! \Illuminate\Support\Str::markdown($proposal->content['next_steps'], ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
            @endif
            @if($live)
                <div class="actions" style="margin-top: 18px;">
                    <form method="POST" action="{{ route('portal.submit', $ref) }}">
                        @csrf <input type="hidden" name="kind" value="quote_request">
                        <button class="btn gold" type="submit">Request a formal quote</button>
                    </form>
                    <form method="POST" action="{{ route('portal.submit', $ref) }}">
                        @csrf <input type="hidden" name="kind" value="survey_request">
                        <button class="btn ghost" type="submit">Book a site survey</button>
                    </form>
                </div>
            @endif
        </div>
    </section>
</main>

<footer class="wrap">
    Quantum 3 Solution is an independent referral partner. {{ $offering->name }} is made and sold by {{ $offering->vendor_name }}, which provides the formal quote and is the seller of record. Statements about product performance are {{ $offering->vendor_name }}'s, quoted from their material.
</footer>
@endsection
