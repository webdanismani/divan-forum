@extends('layouts.app')

@section('title', $title)
@section('crumb', $title)

@section('content')
    <div class="narrow">
        <div class="block">
            <div class="empty">
                <div class="error-code">{{ $code }}</div>
                <h2 style="margin: 6px 0 8px">{{ $title }}</h2>
                <p>{{ $message }}</p>
                <a class="btn btn-primary" href="{{ route('forum.index') }}"><x-icon name="home" :size="16" /> {{ __('Back to the forums') }}</a>
            </div>
        </div>
    </div>
@endsection
