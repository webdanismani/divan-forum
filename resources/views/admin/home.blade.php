@extends('layouts.admin')

@section('title', __('Home page'))
@section('subtitle', __('Which sections the home page shows and what they say. With none of the new sections on, the home page is the classic forum list.'))

@section('content')
    @if ($designedFor)
        <form class="flash info" method="post" action="{{ route('admin.home.adopt') }}" style="align-items: center">
            @csrf
            <x-icon name="droplet" :size="15" />
            <span style="flex: 1">{{ __('The theme in use (:theme) was designed for a certain set of sections.', ['theme' => $designedFor['title']]) }}</span>
            <button class="btn btn-sm" type="submit">{{ __('Use the sections of this theme') }}</button>
        </form>
    @endif

    <div class="acp-split">
    <nav class="acp-sections" data-acp-sections aria-label="{{ __('Sections') }}">
        <a href="#h-sections" class="active">{{ __('Sections') }}</a>
        <a href="#h-slides">{{ __('Hero slides') }}</a>
        <a href="#h-tiles">{{ __('Quick tiles') }}</a>
        <a href="#h-news">{{ __('News cards') }}</a>
        <a href="#h-promos">{{ __('Promotion boxes') }}</a>
        <a href="#h-social">{{ __('Social media') }}</a>
    </nav>
    <form method="post" action="{{ route('admin.home.update') }}" enctype="multipart/form-data">
        @csrf @method('PUT')

        @if ($errors->any())
            <div class="flash error"><x-icon name="alert" :size="15" /> {{ $errors->first() }}</div>
        @endif

        <section class="block" id="h-sections">
            <div class="block-head"><h2>{{ __('Sections') }}</h2></div>
            <div class="block-body">
                @foreach (\App\Forum\Home::SECTIONS as $key => [$label, $default])
                    @if ($key === 'latest')<div class="acp-cap" style="margin: 12px 0 4px; padding: 0">{{ __('Parts of the classic home page') }}</div>@endif
                    <label class="check" style="display: flex; margin: 5px 0"><input type="checkbox" name="sections[{{ $key }}]" value="1" @checked($home->enabled($key))> {{ __($label) }}</label>
                @endforeach
            </div>
        </section>

        <section class="block" id="h-slides">
            <div class="block-head"><h2>{{ __('Hero slides') }}</h2></div>
            <div class="block-body">
                <p class="muted" style="margin-bottom: 10px">{{ __('A slide needs a headline. In the headline, "|" starts a new line; the highlighted word is printed in the accent colour. Links are a path on the forum (/register) or a full address.') }}</p>
                @for ($i = 0; $i < \App\Forum\Home::MAX_SLIDES; $i++)
                    @php $slide = $slides[$i] ?? []; @endphp
                    <details class="home-row" @if ($slide || $i === 0) open @endif>
                        <summary>{{ __('Slide') }} {{ $i + 1 }}@if ($slide) — {{ str_replace('|', ' ', $slide['title']) }}@endif</summary>
                        <input type="hidden" name="slides[{{ $i }}][image]" value="{{ $slide['image'] ?? '' }}">
                        <div class="form-grid">
                            <div class="field"><label>{{ __('Headline') }}</label><input class="input" name="slides[{{ $i }}][title]" value="{{ old("slides.$i.title", $slide['title'] ?? '') }}" maxlength="120"></div>
                            <div class="field"><label>{{ __('Highlighted word') }}</label><input class="input" name="slides[{{ $i }}][mark]" value="{{ old("slides.$i.mark", $slide['mark'] ?? '') }}" maxlength="60"></div>
                            <div class="field"><label>{{ __('Small line above the headline') }}</label><input class="input" name="slides[{{ $i }}][badge]" value="{{ old("slides.$i.badge", $slide['badge'] ?? '') }}" maxlength="80"></div>
                            <div class="field"><label>{{ __('Text') }}</label><input class="input" name="slides[{{ $i }}][text]" value="{{ old("slides.$i.text", $slide['text'] ?? '') }}" maxlength="300"></div>
                            <div class="field"><label>{{ __('First button') }}</label><input class="input" name="slides[{{ $i }}][b1_label]" value="{{ old("slides.$i.b1_label", $slide['b1_label'] ?? '') }}" maxlength="40"></div>
                            <div class="field"><label>{{ __('First button: link') }}</label><input class="input" name="slides[{{ $i }}][b1_link]" value="{{ old("slides.$i.b1_link", $slide['b1_link'] ?? '') }}" maxlength="300" placeholder="/register"></div>
                            <div class="field"><label>{{ __('Second button') }}</label><input class="input" name="slides[{{ $i }}][b2_label]" value="{{ old("slides.$i.b2_label", $slide['b2_label'] ?? '') }}" maxlength="40"></div>
                            <div class="field"><label>{{ __('Second button: link') }}</label><input class="input" name="slides[{{ $i }}][b2_link]" value="{{ old("slides.$i.b2_link", $slide['b2_link'] ?? '') }}" maxlength="300"></div>
                        </div>
                        <div class="field">
                            <label>{{ __('Picture') }}</label>
                            <div class="row" style="flex-wrap: nowrap; align-items: center; gap: 12px">
                                @if ($url = $home->image($slide['image'] ?? ''))<img src="{{ $url }}" alt="" style="width: 120px; height: 46px; object-fit: cover; border-radius: 4px; flex: none">@endif
                                <input class="input" type="file" name="slide_image[{{ $i }}]" accept="image/jpeg,image/png,image/webp">
                            </div>
                            <div class="hint">{{ __('A wide picture (about 1920×520); JPG, PNG or WebP, at most 2 MB. The text sits on the left, so keep that side calm.') }}</div>
                            @if (filled($slide['image'] ?? ''))<label class="check" style="margin-top: 6px"><input type="checkbox" name="slides[{{ $i }}][remove_image]" value="1"> {{ __('Remove the picture') }}</label>@endif
                        </div>
                    </details>
                @endfor
            </div>
        </section>

        <section class="block" id="h-tiles">
            <div class="block-head"><h2>{{ __('Quick tiles') }}</h2></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Icon') }}</th><th>{{ __('First line') }}</th><th>{{ __('Second line') }}</th><th>{{ __('Link') }}</th></tr></thead>
                    <tbody>
                        @for ($i = 0; $i < \App\Forum\Home::MAX_TILES; $i++)
                            @php $tile = $tiles[$i] ?? []; @endphp
                            <tr>
                                <td style="width: 150px">
                                    <select class="input" name="tiles[{{ $i }}][icon]">
                                        @foreach (\App\Forum\Home::ICONS as $glyph)
                                            <option value="{{ $glyph }}" @selected(($tile['icon'] ?? 'gamepad') === $glyph)>{{ $glyph }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input class="input" name="tiles[{{ $i }}][title]" value="{{ $tile['title'] ?? '' }}" maxlength="40"></td>
                                <td><input class="input" name="tiles[{{ $i }}][text]" value="{{ $tile['text'] ?? '' }}" maxlength="40"></td>
                                <td><input class="input" name="tiles[{{ $i }}][link]" value="{{ $tile['link'] ?? '' }}" maxlength="300" placeholder="/whats-new"></td>
                            </tr>
                        @endfor
                    </tbody>
                </table>
            </div>
        </section>

        <section class="block" id="h-news">
            <div class="block-head"><h2>{{ __('News cards') }}</h2></div>
            <div class="block-body">
                <div class="field">
                    <label for="news-node">{{ __('Forum the cards come from') }}</label>
                    <select class="input" id="news-node" name="news[node_id]">
                        <option value="">{{ __('All forums') }}</option>
                        @foreach ($forums as $item)
                            <option value="{{ $item['node']->id }}" @selected($news['node_id'] === $item['node']->id)>{{ str_repeat('— ', $item['depth']) }}{{ $item['node']->title }}</option>
                        @endforeach
                    </select>
                    <div class="hint">{{ __('The newest threads of this forum are shown as cards. The card picture is the first picture of the opening post; the label is the thread prefix.') }}</div>
                </div>
                <div class="field"><label for="news-title">{{ __('Heading') }}</label><input class="input" id="news-title" name="news[title]" value="{{ $news['title'] }}" maxlength="80" placeholder="{{ __('News') }}"></div>
                <div class="field"><label for="news-count">{{ __('Number of cards') }}</label><input class="input" id="news-count" type="number" name="news[count]" value="{{ $news['count'] }}" min="2" max="8" style="max-width: 120px"></div>
            </div>
        </section>

        <section class="block" id="h-promos">
            <div class="block-head"><h2>{{ __('Promotion boxes') }}</h2></div>
            <div class="block-body">
                <p class="muted" style="margin-bottom: 10px">{{ __('Boxes in the side column, for example for membership packages. A box needs a title.') }}</p>
                @for ($i = 0; $i < \App\Forum\Home::MAX_PROMOS; $i++)
                    @php $promo = $promos[$i] ?? []; @endphp
                    <details class="home-row" @if ($promo || $i === 0) open @endif>
                        <summary>{{ __('Box') }} {{ $i + 1 }}@if ($promo) — {{ $promo['title'] }}@endif</summary>
                        <input type="hidden" name="promos[{{ $i }}][image]" value="{{ $promo['image'] ?? '' }}">
                        <div class="form-grid">
                            <div class="field"><label>{{ __('Title') }}</label><input class="input" name="promos[{{ $i }}][title]" value="{{ $promo['title'] ?? '' }}" maxlength="60"></div>
                            <div class="field">
                                <label>{{ __('Style') }}</label>
                                <select class="input" name="promos[{{ $i }}][style]">
                                    @foreach (\App\Forum\Home::PROMO_STYLES as $style => $styleLabel)
                                        <option value="{{ $style }}" @selected(($promo['style'] ?? 'gold') === $style)>{{ __($styleLabel) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field"><label>{{ __('Text') }}</label><input class="input" name="promos[{{ $i }}][text]" value="{{ $promo['text'] ?? '' }}" maxlength="200"></div>
                            <div class="field"><label>{{ __('Button') }}</label><input class="input" name="promos[{{ $i }}][button]" value="{{ $promo['button'] ?? '' }}" maxlength="40"></div>
                            <div class="field"><label>{{ __('Link') }}</label><input class="input" name="promos[{{ $i }}][link]" value="{{ $promo['link'] ?? '' }}" maxlength="300" placeholder="/upgrades"></div>
                            <div class="field">
                                <label>{{ __('Picture') }}</label>
                                <input class="input" type="file" name="promo_image[{{ $i }}]" accept="image/jpeg,image/png,image/webp">
                                @if (filled($promo['image'] ?? ''))<label class="check" style="margin-top: 6px"><input type="checkbox" name="promos[{{ $i }}][remove_image]" value="1"> {{ __('Remove the picture') }}</label>@endif
                            </div>
                        </div>
                    </details>
                @endfor
            </div>
        </section>

        <section class="block" id="h-social">
            <div class="block-head"><h2>{{ __('Social media') }}</h2></div>
            <div class="block-body">
                @foreach (\App\Forum\Home::NETWORKS as $network => [$name])
                    <div class="field"><label for="social-{{ $network }}">{{ $name }}</label><input class="input" id="social-{{ $network }}" type="url" name="social[{{ $network }}]" value="{{ old("social.$network", $social[$network] ?? '') }}" maxlength="300" placeholder="https://"></div>
                @endforeach
            </div>
        </section>

        <div class="acp-save">
            <button class="btn btn-primary" type="submit"><x-icon name="check" :size="16" /> {{ __('Save') }}</button>
            <a class="btn" href="{{ route('forum.index') }}" target="_blank" rel="noopener">{{ __('View forum') }}</a>
        </div>
    </form>
    </div>
@endsection
