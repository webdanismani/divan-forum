@extends('layouts.app')

@section('title', __('Membership packages'))
@section('crumb', __('Membership packages'))

@section('content')
    <div class="page-head">
        <div class="titles">
            <h1>{{ __('Membership packages') }}</h1>
            <div class="desc">{{ __('Support the forum and get extra privileges.') }}</div>
        </div>
        @auth
            <a class="btn" href="{{ route('wallet.index') }}"><x-icon name="wallet" :size="16" /> {{ __('My balance') }}: {{ forum_money(auth()->user()->balance) }}</a>
        @endauth
    </div>

    @if ($upgrades->isEmpty())
        <div class="block"><div class="empty">{{ __('No packages are on sale at the moment.') }}</div></div>
    @else
        <div class="package-grid">
            @foreach ($upgrades as $upgrade)
                @php $mine = $purchases->get($upgrade->id); @endphp
                <section class="block package">
                    <div class="block-head"><x-icon name="star" :size="16" /><h2>{{ $upgrade->title }}</h2></div>
                    <div class="block-body">
                        <div class="price">{{ forum_money($upgrade->price) }} <small>/ {{ $upgrade->days ? __(':days days', ['days' => $upgrade->days]) : __('permanent') }}</small></div>
                        @if ($upgrade->group)
                            <div style="margin: 8px 0"><x-group-banner :group="$upgrade->group" /></div>
                        @endif
                        @if ($upgrade->description)<p class="muted" style="white-space: pre-line; margin: 8px 0 0">{{ $upgrade->description }}</p>@endif
                        @if ($mine)
                            <div class="flash success" style="margin: 12px 0 0">{{ $mine->ends_at ? __('Active until :date', ['date' => $mine->ends_at->translatedFormat('j M Y')]) : __('Active') }}</div>
                        @endif
                    </div>
                    <div class="block-foot">
                        @auth
                            @if ($mine && ! $mine->ends_at)
                                <span class="muted">{{ __('You already have this package.') }}</span>
                            @else
                                <form class="row" method="post" action="{{ route('upgrades.buy', $upgrade) }}" data-confirm="{{ __('Buy this package?') }}">
                                    @csrf
                                    <button class="btn btn-primary" name="method" value="balance">{{ $mine ? __('Extend from balance') : __('Buy from balance') }}</button>
                                    @if ($cardEnabled)<button class="btn" name="method" value="card"><x-icon name="card" :size="15" /> {{ __('Card') }}</button>@endif
                                    @if ($transferEnabled)<button class="btn" name="method" value="transfer"><x-icon name="bank" :size="15" /> {{ __('Bank transfer') }}</button>@endif
                                </form>
                            @endif
                        @else
                            <a class="btn btn-primary" href="{{ route('login') }}">{{ __('Log in to buy') }}</a>
                        @endauth
                    </div>
                </section>
            @endforeach
        </div>
    @endif
@endsection
