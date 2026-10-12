{{-- The drafted plan, rendered from Markdown with raw HTML escaped. --}}
@if($changeRequest->plan)
    <div class="ra-md">{!! \Illuminate\Support\Str::markdown($changeRequest->plan, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
    <div class="f-light mt-3" style="font-size:.75rem;">Drafted {{ $changeRequest->plan_drafted_at?->diffForHumans() }}</div>
@else
    <p class="f-light mb-0 small">No plan yet. Talk the idea through with the assistant, then press <strong>Draft the plan</strong>.</p>
@endif
