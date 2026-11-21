@props(['name' => 'body', 'value' => '', 'rows' => 8, 'placeholder' => '', 'node' => null, 'post' => null, 'draft' => null, 'required' => true, 'maxlength' => null, 'id' => null, 'note' => null, 'prefilled' => false])
@php
    // Attachments are offered when the editor is told which forum it posts to.
    $service = app(\App\Forum\Attachments::class);
    $canAttach = $node && auth()->check() && app(\App\Forum\Edition::class)->has('attachments') && forum_can('attachment.upload', $node);
    $uploadKey = preg_match('/^[A-Za-z0-9]{32}$/', (string) old('attachment_key')) ? old('attachment_key') : \App\Forum\Attachments::newKey();
    $files = $canAttach ? collect($post?->attachments ?? [])->concat($service->pending($uploadKey, auth()->user())) : collect();
    $emojis = ['😀', '😄', '😁', '😂', '🙂', '😉', '😊', '😍', '😘', '😎', '🤔', '😐', '😴', '😢', '😭', '😡', '😱', '🤝', '👍', '👎', '👏', '🙏', '💪', '🔥', '⭐', '❤️', '💯', '✅', '❌', '⚠️', '💡', '🎉', '🚀', '📌', '📎', '💰', '☕', '👀', '🤖'];
@endphp
<div class="editor" @if ($draft) data-draft="{{ $draft }}" @endif @if ($prefilled && old($name) === null) data-prefilled @endif
    @auth data-mentions="{{ route('members.suggest', [], false) }}" @endauth
    data-l-address="{{ __('Address') }}" data-l-text="{{ __('Text to show') }}" data-l-add="{{ __('Insert') }}" data-l-cancel="{{ __('Cancel') }}"
    data-l-invalid="{{ __('Enter an address that starts with http:// or https://') }}" data-l-media="{{ __('Address of a YouTube, Vimeo, X or Instagram page') }}"
    @if ($canAttach) data-uploader data-url="{{ route('attachments.store') }}" data-node="{{ $node->id }}" data-key="{{ $uploadKey }}"
    data-insert="{{ __('Insert') }}" data-remove="{{ __('Remove') }}" data-failed="{{ __('The file could not be uploaded.') }}" @endif>
    <div class="editor-tabs">
        @auth
            <button type="button" class="editor-tab active" data-write-tab><x-icon name="edit" :size="14" /> {{ __('Write') }}</button>
            <button type="button" class="editor-tab editor-mode" data-visual-toggle title="{{ __('Write with formatting shown instead of codes') }}"><x-icon name="eye" :size="14" /> {{ __('Visual') }}</button>
            <button type="button" class="editor-tab editor-mode" data-preview-toggle data-url="{{ route('preview') }}" data-failed="{{ __('The preview could not be loaded.') }}"><x-icon name="search" :size="14" /> {{ __('Preview') }}</button>
        @endauth
        <span class="spacer"></span>
        @if ($canAttach)
            <label class="editor-attach" title="{{ __('Attach files') }}"><x-icon name="paperclip" :size="15" /> {{ __('Attach files') }}
                <input type="file" multiple hidden accept="{{ collect($service->extensions())->map(fn ($e) => '.'.$e)->implode(',') }}">
            </label>
        @endif
    </div>
    <div class="editor-bar" role="toolbar" aria-label="{{ __('Formatting') }}">
        <span class="editor-group">
            <button type="button" data-bb="b" data-key="b" title="{{ __('Bold') }} (Ctrl+B)"><x-icon name="bold" :size="16" /></button>
            <button type="button" data-bb="i" data-key="i" title="{{ __('Italic') }} (Ctrl+I)"><x-icon name="italic" :size="16" /></button>
            <button type="button" data-bb="u" data-key="u" title="{{ __('Underline') }} (Ctrl+U)"><x-icon name="underline" :size="16" /></button>
            <button type="button" data-bb="s" title="{{ __('Strikethrough') }}"><x-icon name="strike" :size="16" /></button>
        </span>
        <span class="editor-group">
            <details class="editor-drop">
                <summary title="{{ __('Text size') }}"><x-icon name="heading" :size="16" /><x-icon name="chevron-down" :size="11" /></summary>
                <div class="editor-drop-panel">
                    <button type="button" data-open="[size=6]" data-close="[/size]" style="font-size: 18px; font-weight: 600">{{ __('Heading') }}</button>
                    <button type="button" data-open="[size=5]" data-close="[/size]" style="font-size: 16px">{{ __('Large') }}</button>
                    <button type="button" data-open="[size=2]" data-close="[/size]" style="font-size: 12px">{{ __('Small') }}</button>
                </div>
            </details>
            <details class="editor-drop">
                <summary title="{{ __('Text colour') }}"><x-icon name="droplet" :size="16" /><x-icon name="chevron-down" :size="11" /></summary>
                <div class="editor-drop-panel swatches">
                    @foreach (['#d23f3b', '#e8590c', '#d99a00', '#1f9d6b', '#0d9bd8', '#1877d4', '#7b52c7', '#5b6878'] as $colour)
                        <button type="button" data-open="[color={{ $colour }}]" data-close="[/color]" style="background: {{ $colour }}" title="{{ $colour }}" aria-label="{{ $colour }}"></button>
                    @endforeach
                </div>
            </details>
        </span>
        <span class="editor-group">
            <button type="button" data-bb="url" data-key="k" title="{{ __('Link') }} (Ctrl+K)"><x-icon name="link" :size="16" /></button>
            <button type="button" data-bb="img" title="{{ __('Image') }}"><x-icon name="image" :size="16" /></button>
            <button type="button" data-bb="media" title="{{ __('Video or social media link') }}"><x-icon name="play" :size="15" /></button>
        </span>
        <span class="editor-group">
            <button type="button" data-bb="list" title="{{ __('List') }}"><x-icon name="list" :size="16" /></button>
            <button type="button" data-bb="olist" title="{{ __('Numbered list') }}"><x-icon name="list-ol" :size="16" /></button>
            <button type="button" data-bb="center" title="{{ __('Centre') }}"><x-icon name="align-center" :size="16" /></button>
        </span>
        <span class="editor-group">
            <button type="button" data-bb="quote" title="{{ __('Quote') }}"><x-icon name="quote" :size="16" /></button>
            <button type="button" data-bb="code" title="{{ __('Code block') }}"><x-icon name="code" :size="16" /></button>
            <button type="button" data-bb="icode" title="{{ __('Inline code') }}"><x-icon name="terminal" :size="16" /></button>
            <button type="button" data-bb="spoiler" title="{{ __('Spoiler') }}"><x-icon name="eye" :size="16" /></button>
        </span>
        <span class="editor-group">
            <button type="button" data-emoji-toggle title="{{ __('Emoji') }}"><x-icon name="smile" :size="16" /></button>
            @if (auth()->check() && forum_can('replies.saved'))
                <button type="button" data-saved-toggle data-url="{{ route('replies.index', [], false) }}" title="{{ __('Saved replies') }}"><x-icon name="bookmark" :size="16" /></button>
            @endif
        </span>
    </div>
    @if (auth()->check() && forum_can('replies.saved'))
        <div class="saved-panel" data-empty="{{ __('You have no saved replies yet.') }}" hidden>
            <div class="saved-list"></div>
            <a class="saved-manage" href="{{ route('replies.index') }}" target="_blank"><x-icon name="edit" :size="13" /> {{ __('Manage saved replies') }}</a>
        </div>
    @endif
    <div class="mention-list" hidden></div>
    <div class="emoji-panel" hidden>@foreach ($emojis as $emoji)<button type="button">{{ $emoji }}</button>@endforeach</div>
    <textarea name="{{ $name }}" rows="{{ $rows }}" placeholder="{{ $placeholder }}" @if ($id) id="{{ $id }}" @endif @if ($maxlength) maxlength="{{ $maxlength }}" @endif @required($required)>{{ old($name, $value) }}</textarea>
    <div class="editor-visual bb" contenteditable="true" role="textbox" aria-multiline="true" style="min-height: {{ $rows * 22 }}px" hidden></div>
    <div class="editor-preview bb" style="min-height: {{ $rows * 22 }}px" hidden></div>
    <div class="editor-status">
        <span>{{ $note ?? __('BBCode and @mentions work here. Your text is kept as a draft in this browser while you write.') }}</span>
        <span class="spacer"></span>
        <span data-editor-count data-label="{{ $maxlength ? __(':count / :max characters', ['max' => forum_number($maxlength)]) : __(':count characters') }}" @if ($maxlength) data-max="{{ $maxlength }}" @endif></span>
    </div>
    <div class="editor-note" data-draft-note hidden>{{ __('Your unsent draft was restored.') }} <button type="button" class="link-btn">{{ __('Discard it') }}</button></div>
    @if ($canAttach)
        <input type="hidden" name="attachment_key" value="{{ $uploadKey }}">
        <div class="editor-files">
            <ul class="file-list" data-files>
                @foreach ($files as $file)
                    <li data-bbcode="{{ $file->bbcode() }}" data-delete="{{ route('attachments.destroy', $file) }}">
                        @if ($file->is_image)<img src="{{ $file->url(true) }}" alt="">@else<span class="file-icon"><x-icon name="paperclip" :size="16" /></span>@endif
                        <span class="file-name truncate">{{ $file->filename }} <small class="muted">{{ $file->humanSize() }}</small></span>
                        <button type="button" class="btn btn-sm" data-file-insert>{{ __('Insert') }}</button>
                        <button type="button" class="btn btn-sm btn-ghost btn-danger" data-file-remove title="{{ __('Remove') }}"><x-icon name="trash" :size="14" /></button>
                    </li>
                @endforeach
            </ul>
            <div class="muted small" data-upload-hint>{{ __('Drop files here or paste an image.') }} {{ strtoupper(implode(', ', $service->extensions())) }} · {{ __(':size at most', ['size' => number_format($service->maxKilobytes() / 1024, 1, ',', '.').' MB']) }}</div>
        </div>
    @endif
</div>
@error($name)<div class="field"><div class="error">{{ $message }}</div></div>@enderror
