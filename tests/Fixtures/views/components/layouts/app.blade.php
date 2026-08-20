{{-- Stands in for the consuming application's admin layout, so the package's
     own suite can render the published views. Deliberately bare: what is under
     test is the view, not anybody's chrome. --}}
@props(['title' => null, 'heading' => null, 'subheading' => null])

<!DOCTYPE html>
<html lang="en">

<head>
    <title>{{ $title }}</title>
</head>

<body>
    <h1>{{ $heading }}</h1>
    <p>{{ $subheading }}</p>

    {{ $actions ?? '' }}

    @if (session('status'))
        <p>{{ session('status') }}</p>
    @endif

    @if (session('error'))
        <p>{{ session('error') }}</p>
    @endif

    {{ $slot }}
</body>

</html>
