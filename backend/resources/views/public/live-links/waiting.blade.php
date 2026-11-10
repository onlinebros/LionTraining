{{-- A live link with no destination yet, or switched off. Shown with a 200 and
     no caching: the same link starts working the moment staff fill it in. --}}
@extends('errors.layout')

@section('code', 'Live')
@section('title', 'Nothing live here right now')

@section('body')
    <p>
        This link takes you to our {{ \Illuminate\Support\Str::lower($link->label) }} while one is running.
        There isn't one on at the moment. Check the time you were given, then use this same link again.
        It will take you straight in.
    </p>
    <a href="{{ url()->current() }}" class="btn btn-outline-light btn-sm">Try again</a>
@endsection
