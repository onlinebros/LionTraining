{{-- The pop-up behind the header button: starts a change request linked to
     the page it was opened from. Super admins only. --}}
@if(auth()->user()?->isSuperAdmin())
@php $reopen = old('_ra_modal') && $errors->any(); @endphp
<div class="modal fade" id="requestAssistantModal" tabindex="-1" aria-labelledby="requestAssistantModalLabel">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="requestAssistantModalLabel">Suggest a change</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="{{ route('admin.change-requests.store') }}">
                @csrf
                <input type="hidden" name="_ra_modal" value="1">
                <input type="hidden" name="source_url" id="raSourceUrl" value="{{ $reopen ? old('source_url') : '' }}">
                <input type="hidden" name="source_title" id="raSourceTitle" value="{{ $reopen ? old('source_title') : '' }}">
                <div class="modal-body">
                    @if($reopen)
                        <div class="alert alert-danger py-2 small mb-3">{{ $errors->first() }}</div>
                    @endif
                    <p class="small f-light mb-3">
                        About this page: <code id="raSourceShown">{{ $reopen ? parse_url((string) old('source_url'), PHP_URL_PATH) : '' }}</code>.
                        The assistant starts by looking at how this page works, asks you questions, and drafts a plan
                        for the team to review. Nothing changes until the team decides to build it.
                    </p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="raTitle">Short title</label>
                        <input id="raTitle" type="text" name="title" maxlength="200" required class="form-control"
                               value="{{ $reopen ? old('title') : '' }}" placeholder="e.g. Add a phone column to this list">
                    </div>
                    <div>
                        <label class="form-label fw-semibold" for="raBody">What would you like changed, and why?</label>
                        <textarea id="raBody" name="body" rows="6" maxlength="10000" required class="form-control"
                                  placeholder="Who is it for, what problem does it solve, and how would you know it worked?">{{ $reopen ? old('body') : '' }}</textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="{{ route('admin.change-requests.index') }}" class="btn btn-link btn-sm me-auto">All change requests</a>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Start the conversation</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var modal = document.getElementById('requestAssistantModal');
        modal.addEventListener('show.bs.modal', function () {
            if (document.getElementById('raSourceUrl').value) { return; }
            // Path only is shown; the server also drops any query string.
            document.getElementById('raSourceUrl').value = window.location.origin + window.location.pathname;
            document.getElementById('raSourceTitle').value = document.title.split(' | ')[0];
            document.getElementById('raSourceShown').textContent = window.location.pathname;
        });
        modal.addEventListener('shown.bs.modal', function () { document.getElementById('raTitle').focus(); });
        @if($reopen)
            new bootstrap.Modal(modal).show();
        @endif
    });
</script>
@endif
