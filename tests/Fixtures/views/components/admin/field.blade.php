@props(['label', 'name', 'hint' => null, 'required' => false])

<div {{ $attributes->only('class') }}>
    <label for="{{ $name }}">{{ $label }}</label>
    {{ $slot }}
    @if ($hint)
        <p>{{ $hint }}</p>
    @endif
    @error($name)
        <p>{{ $message }}</p>
    @enderror
</div>
