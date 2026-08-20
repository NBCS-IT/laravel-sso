@props(['name', 'type' => 'text', 'value' => null])

<input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ old($name, $value) }}" {{ $attributes }} />
