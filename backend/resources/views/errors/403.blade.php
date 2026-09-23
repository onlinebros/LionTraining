@extends('errors.layout')

@section('code', '403')
@section('title', 'That part is not open to your account')

@section('body')
    <p class="mb-0">
        You are signed in, but this page needs access your account does not have.
        If you think it should, support can check it.
    </p>
@endsection
