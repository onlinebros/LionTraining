@extends('errors.layout')

@section('code', '500')
@section('title', 'Something went wrong on our end')

@section('body')
    @php $reference = \App\Support\ErrorReference::get(); @endphp

    @if($reference)
        {{--
            Only said when it is true. The reporter in bootstrap/app.php writes
            the error to error_logs and leaves its id behind; finding one here
            means the report is already on the board our technical staff work
            from, and nobody needs to be asked to describe what they saw.
        --}}
        <p class="mb-2">
            This one is ours, not yours — nothing you did caused it, and nothing you
            entered has been lost by it.
        </p>
        <p class="mb-0">
            <strong>The error has already been reported to our technical staff</strong>,
            automatically, the moment it happened. We are working to resolve it as
            soon as possible. There is nothing you need to send us.
        </p>
    @else
        {{--
            No reference means the write to error_logs failed too — almost
            always because the database is the thing that is down. Claiming it
            had been reported would be a guess, and a page that guesses about
            this is worse than one that asks.
        --}}
        <p class="mb-2">
            This one is ours, not yours — nothing you did caused it.
        </p>
        <p class="mb-0">
            We could not record the details automatically this time, so if it keeps
            happening please tell support what you were doing when it did.
        </p>
    @endif
@endsection

@if(\App\Support\ErrorReference::get())
    @section('reference')
        Reference <code>#{{ \App\Support\ErrorReference::get() }}</code> — quote this if you contact support about it.
    @endsection
@endif
