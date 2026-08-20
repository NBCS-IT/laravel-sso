@props(['variant' => 'primary', 'href' => null])

@if ($href)
    <a href="{{ $href }}" {{ $attributes }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'submit']) }}>{{ $slot }}</button>
@endif
