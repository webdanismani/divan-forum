@php $staff = auth()->user()?->group?->is_staff || auth()->user()?->isAdmin(); @endphp
@foreach ($shouts as $shout)
    <div class="shout" data-shout="{{ $shout->id }}">
        <time class="muted" datetime="{{ $shout->created_at?->toAtomString() }}">{{ $shout->created_at?->format('H:i') }}</time>
        <x-username :user="$shout->user" />:
        <span class="shout-text">{{ $shout->body }}</span>
        @if ($staff)<button class="shout-del" type="button" data-shout-delete="{{ route('shoutbox.destroy', $shout) }}" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}">×</button>@endif
    </div>
@endforeach
