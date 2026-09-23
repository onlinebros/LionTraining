@php
    /*
     * "Land here when I sign in", for the profile menu of whichever shell the
     * person is currently in.
     *
     * Renders nothing at all unless the account actually holds more than one
     * section — an ordinary member has one place to be, and a setting offering
     * a choice of one is worse than no setting.
     */
    $landingUser    = auth()->user();
    $landingOptions = $landingUser?->landingOptions() ?? [];
    $landingCurrent = $landingUser?->landing_preference;
@endphp

@if (count($landingOptions) > 1)
    <li class="border-top pt-2 mt-1">
        <div class="px-3 pb-1">
            <label class="small f-light d-block mb-1" for="landing-preference">Land here when I sign in</label>
            <form method="POST" action="{{ route('preferences.landing') }}">
                @csrf
                <select name="landing" id="landing-preference"
                        class="form-select form-select-sm"
                        onchange="this.form.submit()">
                    @foreach ($landingOptions as $key => $label)
                        <option value="{{ $key }}" @selected($landingCurrent === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
        </div>
    </li>
@endif
