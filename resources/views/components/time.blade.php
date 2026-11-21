@props(['at'])
@if ($at)<time datetime="{{ $at->toIso8601String() }}" title="{{ $at->translatedFormat('j F Y H:i') }}" {{ $attributes }}>{{ $at->diffForHumans() }}</time>@endif
