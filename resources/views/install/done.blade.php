<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Installation') }} · {{ config('forum.product.name') }}</title>
    <link rel="stylesheet" href="{{ asset('assets/forum.css') }}">
</head>
<body>
<main class="install-page">
    <h1><span class="brand-mark"><x-icon name="check" :size="20" /></span> {{ __('The forum is installed') }}</h1>

    <section class="block">
        <div class="block-body">
            <p>{{ __('Everything is ready. Sign in as :name to open the admin panel and finish the settings.', ['name' => $username]) }}</p>
            @unless ($storageLinked)
                <div class="flash info">{{ __('The "public/storage" link could not be created on this server. Create a link from public/storage to storage/app/public so that avatars and banners are shown.') }}</div>
            @endunless
            <div class="form-actions">
                <a class="btn btn-primary" href="{{ $url }}/login">{{ __('Log in') }}</a>
                <a class="btn" href="{{ $url }}">{{ __('Open the forum') }}</a>
            </div>
        </div>
    </section>
</main>
</body>
</html>
