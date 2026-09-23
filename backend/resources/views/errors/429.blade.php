@extends('errors.layout')

@section('code', '429')
@section('title', 'Too many attempts')

@section('body')
    <p class="mb-0">
        That came through faster than we allow. Wait a minute and try again — the
        limit clears on its own, and nothing about your account has changed.
    </p>
@endsection
