@foreach (['success', 'error', 'info'] as $type)
    @if (session($type))
        <div class="flash {{ $type }}" role="status">{{ session($type) }}</div>
    @endif
@endforeach
@if ($errors->any() && ! ($quietErrors ?? false))
    <div class="flash error" role="alert">
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
