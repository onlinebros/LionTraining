{{--
    When a showing starts, said once and said the same way everywhere.

    Renders the Eastern time on the server — that is the booking time and the
    one everyone in the company quotes — and lets the browser append the
    viewer's own time beside it, but only when the two differ. Somebody in
    Florida should not be told "7:00 PM ET · 7:00 PM your time".

    Usage:
        @include('partials.presentation-time', ['presentation' => $presentation])
        @include('partials.presentation-time', ['presentation' => $p, 'withYear' => true])
--}}
@php $withYear = $withYear ?? false; @endphp

@if($presentation->isOnDemand())
    {{-- An always-open share has no start time. Printing the moment it was
         created reads as a schedule and would have people waiting for it. --}}
    <span class="pres-time">Always open</span>
@else
<span class="pres-time" data-utc="{{ $presentation->scheduledAtIso() }}" data-zone="{{ \App\Models\Presentation::bookingTimezone() }}">
    <span class="pres-time__booked">{{ $presentation->scheduledLabel($withYear) }}</span><span class="pres-time__local"></span>
</span>
@endif

@once
@push('scripts')
<script>
/*
 * Fill in "· 4:00 PM PDT your time" next to each booked time.
 *
 * Only when the viewer is genuinely in a different zone — comparing the printed
 * offsets rather than the zone names, so America/Detroit and America/New_York
 * are correctly treated as the same clock and the reader is not told their own
 * time twice.
 */
(function () {
    var nodes = document.querySelectorAll('.pres-time');
    if (!nodes.length || typeof Intl === 'undefined') return;

    var viewerZone = Intl.DateTimeFormat().resolvedOptions().timeZone;

    function offsetLabel(date, zone) {
        try {
            return new Intl.DateTimeFormat('en-US', {
                timeZone: zone, timeZoneName: 'short'
            }).format(date).split(', ').pop();
        } catch (e) {
            return null;
        }
    }

    nodes.forEach(function (node) {
        var iso = node.getAttribute('data-utc');
        var bookingZone = node.getAttribute('data-zone');
        if (!iso || !viewerZone) return;

        var when = new Date(iso);
        if (isNaN(when.getTime())) return;

        // Same wall clock as the booking zone? Then there is nothing to add.
        if (offsetLabel(when, viewerZone) === offsetLabel(when, bookingZone)) return;

        var local;
        try {
            local = new Intl.DateTimeFormat(undefined, {
                weekday: 'short', day: 'numeric', month: 'short',
                hour: 'numeric', minute: '2-digit', timeZoneName: 'short'
            }).format(when);
        } catch (e) {
            return;
        }

        var target = node.querySelector('.pres-time__local');
        if (target) target.textContent = ' · ' + local + ' your time';
    });
})();
</script>
@endpush
@endonce
