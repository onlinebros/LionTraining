{{-- The client portal: who is invited, what they sent, questions waiting. --}}
@php
    $published = $prospect->publishedProposal;
    $waiting = $prospect->submissions->filter->isWaitingForAnswer();
@endphp
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold">Client portal</h6>
        @if($waiting->isNotEmpty())<span class="badge badge-light-danger">{{ $waiting->count() }} question{{ $waiting->count() === 1 ? '' : 's' }} to answer</span>@endif
    </div>
    <div class="card-body">
        @if(! $published)
            <p class="f-light small mb-0">Once a proposal is published, invite the prospect's people here. Each gets a sign-in link by email, valid for {{ \App\Models\PortalInvite::LINK_DAYS }} days.</p>
        @else
            <p class="small mb-2">Showing version {{ $published->version }} at <code>{{ route('portal.show', $prospect->portal_ref) }}</code>. Only invited people can open it.</p>
            <p class="small f-light">{{ $portalAssistant ? 'The assistant answers their questions from approved claims and shareable documents, and passes anything else to you.' : 'Their questions come to you here. (No Claude API key is set for the portal assistant.)' }}</p>

            @foreach($waiting as $q)
                <div class="alert alert-warning small">
                    <strong>{{ $q->invite?->name ?: $q->invite?->email }} asked:</strong> {{ $q->body }}
                    <form method="POST" action="{{ route('admin.proposal-studio.questions.answer', [$prospect, $q]) }}" class="mt-2">
                        @csrf
                        <textarea name="answer" rows="3" class="form-control form-control-sm mb-2" required maxlength="10000" placeholder="Your answer. They are emailed, and it shows in their portal."></textarea>
                        <button class="btn btn-primary btn-sm">Send answer</button>
                    </form>
                </div>
            @endforeach

            <h6 class="fw-bold small text-uppercase f-light mt-3">Invited</h6>
            @forelse($prospect->invites as $invite)
                <div class="d-flex justify-content-between align-items-center small py-1">
                    <div>
                        {{ $invite->name ? $invite->name.' · ' : '' }}{{ $invite->email }}
                        <span class="f-light">· {{ $invite->revoked_at ? 'removed' : ($invite->last_seen_at ? 'last opened '.$invite->last_seen_at->diffForHumans() : 'not opened yet') }}</span>
                    </div>
                    @if($invite->isActive())
                        <div class="d-flex gap-1">
                            <form method="POST" action="{{ route('admin.proposal-studio.invites.resend', [$prospect, $invite]) }}">@csrf<button class="btn btn-link btn-sm p-0">Resend</button></form>
                            <form method="POST" action="{{ route('admin.proposal-studio.invites.revoke', [$prospect, $invite]) }}">@csrf @method('DELETE')<button class="btn btn-link btn-sm p-0 text-danger">Remove</button></form>
                        </div>
                    @endif
                </div>
            @empty
                <p class="small f-light">Nobody yet.</p>
            @endforelse
            <form method="POST" action="{{ route('admin.proposal-studio.invites.store', $prospect) }}" class="row g-2 mt-1">
                @csrf
                <div class="col-sm-5"><input type="text" name="name" class="form-control form-control-sm" placeholder="Name" maxlength="255" value="{{ old('name', $prospect->invites->isEmpty() ? $prospect->contact_name : '') }}"></div>
                <div class="col-sm-5"><input type="email" name="email" class="form-control form-control-sm" placeholder="Email" required maxlength="255" value="{{ old('email', $prospect->invites->isEmpty() ? $prospect->contact_email : '') }}"></div>
                <div class="col-sm-2"><button class="btn btn-primary btn-sm w-100">Invite</button></div>
            </form>

            @php $sent = $prospect->submissions->reject->isWaitingForAnswer(); @endphp
            @if($sent->isNotEmpty())
                <h6 class="fw-bold small text-uppercase f-light mt-3">From the portal</h6>
                @foreach($sent->take(10) as $s)
                    <div class="small py-1 border-bottom">
                        <strong>{{ $s->kindLabel() }}</strong> <span class="f-light">· {{ $s->invite?->name ?: $s->invite?->email }}, {{ $s->created_at->diffForHumans() }}@if($s->kind === 'question') · answered by {{ $s->answered_by }}@endif</span>
                        @if($s->body)<div>{{ \Illuminate\Support\Str::limit($s->body, 200) }}</div>@endif
                        @foreach($s->files ?? [] as $i => $file)
                            <a href="{{ route('admin.proposal-studio.submissions.file', [$prospect, $s, $i]) }}">{{ $file['name'] }}</a>@if(!$loop->last), @endif
                        @endforeach
                    </div>
                @endforeach
            @endif

            @if($events->isNotEmpty())
                <details class="mt-3 small">
                    <summary class="fw-bold">Activity</summary>
                    @foreach($events as $e)
                        <div class="f-light">{{ $e->created_at->format('M j, g:i a') }} · {{ $e->invite?->email ?? 'someone' }} · {{ str_replace('_', ' ', $e->type) }}@if(!empty($e->meta['title'])) "{{ $e->meta['title'] }}"@endif</div>
                    @endforeach
                </details>
            @endif
        @endif
    </div>
</div>
