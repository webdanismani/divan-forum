{{-- The banner of a user group in the design the administrator chose for it. --}}
@props(['group', 'block' => false, 'text' => null])
@php $vars = $group->bannerVars(); @endphp
<span {{ $attributes->class(['gbanner', 'gb-'.$group->bannerStyle(), 'block' => $block]) }} @if ($vars) style="{{ $vars }}" @endif>
    @if ($group->banner_icon && in_array($group->banner_icon, \App\Models\UserGroup::BANNER_ICONS, true))<span class="gb-icon"><x-icon :name="$group->banner_icon" :size="13" /></span>@endif
    <span class="gb-text">{{ $text ?? ($group->badge ?: $group->name) }}</span>
</span>
