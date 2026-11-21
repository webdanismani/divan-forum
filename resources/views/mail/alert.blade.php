<x-mail::message>
# {{ \App\Mail\AlertMail::plain($greeting) }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
<x-mail::button :url="$buttonUrl">
{{ $buttonText }}
</x-mail::button>

<x-mail::subcopy>
{{ __('You can choose which e-mails you receive in your account settings:') }} [{{ route('account.edit') }}]({{ route('account.edit') }})
</x-mail::subcopy>
</x-mail::message>
