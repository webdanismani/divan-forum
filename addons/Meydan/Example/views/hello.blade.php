@extends('layouts.app')

@section('title', __('Example add-on'))
@section('crumb', __('Example add-on'))

@section('content')
    <section class="block">
        <div class="block-head"><h2>{{ __('Example add-on') }}</h2></div>
        <div class="block-body">{{ __('This page is added by an add-on.') }}</div>
    </section>
@endsection
