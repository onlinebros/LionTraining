@extends('errors.layout')

@section('code', '419')
@section('title', 'Your session expired')

@section('body')
    {{--
        The one error here that is routine rather than broken: a form left open
        long enough for the CSRF token to age out. It is worth saying plainly,
        because "419" tells a member nothing and the fix is simply to sign in
        again.
    --}}
    <p class="mb-0">
        You were away long enough that we signed you out for safety. Log in again
        and carry on — if you were part way through a form, you will need to fill
        it in once more.
    </p>
@endsection
