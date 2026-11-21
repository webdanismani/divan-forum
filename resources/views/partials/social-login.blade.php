@if (! empty($providers))
    <div class="social-login">
        <div class="social-sep"><span>{{ __('or') }}</span></div>
        @foreach ($providers as $key => $label)
            <a class="btn btn-block" href="{{ route('social.redirect', $key) }}">{{ __('Continue with :provider', ['provider' => $label]) }}</a>
        @endforeach
    </div>
@endif
