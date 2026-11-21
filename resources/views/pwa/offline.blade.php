<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('No connection') }} | {{ setting('board.name') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; background: #f3f5f8; color: #1d2733; }
        main { max-width: 420px; margin: 16px; padding: 28px 24px; text-align: center; background: #fff; border: 1px solid #d9dfe7; border-radius: 6px; }
        h1 { margin: 0 0 8px; font-size: 19px; }
        p { margin: 0 0 18px; color: #5b6878; }
        button { font: inherit; font-weight: 600; padding: 8px 18px; color: #fff; background: #1f6feb; border: 0; border-radius: 4px; cursor: pointer; }
        @media (prefers-color-scheme: dark) { body { background: #12161d; color: #e4e9f0; } main { background: #1a2029; border-color: #2b3440; } p { color: #9aa7b6; } }
    </style>
</head>
<body>
<main>
    <h1>{{ __('No connection') }}</h1>
    <p>{{ __('The forum cannot be reached right now. Check your internet connection and try again.') }}</p>
    <button type="button" onclick="location.reload()">{{ __('Try again') }}</button>
</main>
</body>
</html>
