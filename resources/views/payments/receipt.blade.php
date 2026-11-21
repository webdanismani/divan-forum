<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Receipt') }} {{ $receipt['number'] }} | {{ setting('board.name') }}</title>
    <style>
        body { margin: 0; padding: 24px 16px; font: 14px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #1d2733; background: #eef1f5; }
        .sheet { max-width: 720px; margin: 0 auto; padding: 32px; background: #fff; border: 1px solid #d9dfe7; }
        header { display: flex; justify-content: space-between; gap: 24px; padding-bottom: 16px; border-bottom: 2px solid #1d2733; }
        h1 { margin: 0; font-size: 22px; }
        h2 { margin: 0 0 4px; font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: #5b6878; }
        .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin: 20px 0; }
        .pre { white-space: pre-line; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { padding: 9px 8px; text-align: left; border-bottom: 1px solid #d9dfe7; }
        th { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: #5b6878; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        tfoot td { border-bottom: 0; font-weight: 700; font-size: 16px; }
        .note { margin-top: 24px; font-size: 12px; color: #5b6878; }
        .tools { max-width: 720px; margin: 0 auto 12px; text-align: right; }
        button { font: inherit; font-weight: 600; padding: 7px 16px; color: #fff; background: #1f6feb; border: 0; border-radius: 4px; cursor: pointer; }
        @media print { body { padding: 0; background: #fff; } .sheet { border: 0; padding: 0; } .tools { display: none; } }
    </style>
</head>
<body>
<div class="tools"><button type="button" onclick="window.print()">{{ __('Print') }}</button></div>
<div class="sheet">
    <header>
        <div>
            <h1>{{ __('Receipt') }}</h1>
            <div>{{ $receipt['number'] }} · {{ $receipt['date']?->format('d.m.Y H:i') }}</div>
        </div>
        <div style="text-align: right"><strong>{{ setting('board.name') }}</strong><br>{{ url('/') }}</div>
    </header>

    <div class="cols">
        <div>
            <h2>{{ __('Seller') }}</h2>
            <div class="pre">{{ filled(setting('ads.receipt_seller')) ? setting('ads.receipt_seller') : setting('board.name') }}</div>
        </div>
        <div>
            <h2>{{ __('Customer') }}</h2>
            <div><strong>{{ $receipt['name'] }}</strong></div>
            @if ($receipt['address'])<div class="pre">{{ $receipt['address'] }}</div>@endif
            @if ($receipt['phone'])<div>{{ $receipt['phone'] }}</div>@endif
        </div>
    </div>

    <table>
        <thead><tr><th>{{ __('Description') }}</th><th>{{ __('Payment method') }}</th><th class="num">{{ __('Amount') }}</th></tr></thead>
        <tbody>
            <tr><td>{{ $receipt['title'] }}</td><td>{{ $receipt['method'] }}</td><td class="num">{{ forum_money($receipt['amount'] + $receipt['discount']) }}</td></tr>
            @if ($receipt['discount'] > 0)
                <tr><td colspan="2">{{ __('Discount') }}</td><td class="num">−{{ forum_money($receipt['discount']) }}</td></tr>
            @endif
        </tbody>
        <tfoot><tr><td colspan="2">{{ __('Total paid') }}</td><td class="num">{{ forum_money($receipt['amount']) }}</td></tr></tfoot>
    </table>

    <p class="note">{{ __('This document confirms that the payment was received. It is not a tax invoice.') }}</p>
</div>
</body>
</html>
