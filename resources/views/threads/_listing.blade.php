{{-- Marketplace box on a listing thread: type, price, state, the seller's trader score, feedback. --}}
@php
    $listing = $thread->listing;
    $owner = $thread->user;
    $me = auth()->user();
    $manage = $me && ($thread->user_id === $me->id || forum_can('mod.editAny', $node));
    [$stateLabel, $stateColor] = \App\Models\Listing::STATES[$listing->status] ?? ['Open', ''];
    $score = $owner ? \App\Forum\Market::score($owner) : null;
@endphp
<section class="block listing-box" id="listing" style="margin-bottom: 12px">
    <div class="block-head">
        <x-icon name="tag" :size="16" /><h2>{{ __(\App\Models\Listing::TYPES[$listing->type] ?? 'For sale') }}</h2>
        <span class="badge {{ $stateColor }}">{{ __($stateLabel) }}</span>
        @if ($listing->isFeatured())<span class="badge amber"><x-icon name="star" :size="12" /> {{ __('Showcase') }}</span>@endif
        <span class="spacer"></span>
        <a href="{{ route('market.index') }}">{{ __('Marketplace') }}</a>
    </div>
    <div class="block-body row" style="align-items: center">
        <div class="listing-price">{{ $listing->price !== null ? forum_money($listing->price) : __('Ask for the price') }}</div>
        @if ($owner)
            <div class="listing-seller">
                <x-avatar :user="$owner" size="sm" />
                <div>
                    <x-username :user="$owner" />
                    <div class="muted small">
                        <a href="{{ route('members.feedback', $owner) }}">
                            @if ($score !== null)
                                {{ __('Trader score: :score%', ['score' => $score]) }}
                                (<span class="text-green">+{{ $owner->trade_positive }}</span> / {{ $owner->trade_neutral }} / <span class="text-red">−{{ $owner->trade_negative }}</span>)
                            @else
                                {{ __('No trader feedback yet') }}
                            @endif
                        </a>
                        @if ($owner->phone_verified_at) · <span class="text-green"><x-icon name="check" :size="12" /> {{ __('Phone verified') }}</span>@endif
                    </div>
                </div>
            </div>
        @endif
        <span class="spacer"></span>
        @if ($manage)
            <form class="row" method="post" action="{{ route('listings.update', $thread) }}">
                @csrf @method('PUT')
                <input class="input" style="width: 130px" type="number" name="price" value="{{ $listing->price }}" min="0" step="0.01" placeholder="{{ __('Price') }}" aria-label="{{ __('Price') }}">
                <select class="input" style="width: auto" name="status" aria-label="{{ __('State') }}">
                    @foreach (\App\Models\Listing::STATES as $key => [$label])
                        <option value="{{ $key }}" @selected($listing->status === $key)>{{ __($label) }}</option>
                    @endforeach
                </select>
                <button class="btn" type="submit">{{ __('Update') }}</button>
            </form>
        @endif
    </div>
    @if ($rateTargets->isNotEmpty())
        <form class="block-foot" method="post" action="{{ route('listings.rate', $thread) }}">
            @csrf
            <div class="row">
                <strong>{{ __('Leave trader feedback') }}</strong>
                <select class="input" style="width: auto" name="to" aria-label="{{ __('Member') }}">
                    @foreach ($rateTargets as $target)<option value="{{ $target->id }}">{{ $target->username }}</option>@endforeach
                </select>
                <select class="input" style="width: auto" name="rating" aria-label="{{ __('Rating') }}">
                    @foreach (\App\Models\TradeFeedback::RATINGS as $value => [$label])<option value="{{ $value }}">{{ __($label) }}</option>@endforeach
                </select>
                <input class="input" style="flex: 1; min-width: 200px" name="comment" value="{{ old('comment') }}" minlength="5" maxlength="300" placeholder="{{ __('How did the deal go?') }}" required>
                <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
            </div>
            @error('comment')<div class="error" style="margin-top: 6px">{{ $message }}</div>@enderror
        </form>
    @endif
</section>
