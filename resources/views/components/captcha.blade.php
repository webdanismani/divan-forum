@php $guard = app(\App\Forum\Spam\Guard::class); @endphp
<input type="hidden" name="form_stamp" value="{{ $guard->stamp() }}">
{{-- Left empty by people; bots tend to fill every field. --}}
<div style="position: absolute; left: -9999px" aria-hidden="true">
    <label>Website <input type="text" name="{{ \App\Forum\Spam\Guard::HONEYPOT }}" value="" tabindex="-1" autocomplete="off"></label>
</div>
@if ($guard->provider() === 'turnstile')
    <div class="field">
        <div class="cf-turnstile" data-sitekey="{{ $guard->siteKey() }}"></div>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    </div>
@elseif ($guard->provider() === 'recaptcha')
    <div class="field">
        <div class="g-recaptcha" data-sitekey="{{ $guard->siteKey() }}"></div>
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    </div>
@endif
@error('captcha')<div class="field"><div class="error">{{ $message }}</div></div>@enderror
