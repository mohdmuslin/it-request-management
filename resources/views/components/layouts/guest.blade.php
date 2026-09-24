<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">

    <title>{{ isset($title) ? $title.' — IT Request Management' : 'IT Request Management' }}</title>

    {{--
        No webfont, deliberately.

        The alternative is a render-blocking request to a font CDN on every page
        load. For an internal system on office and mobile connections, the system
        font stack renders immediately and looks native on every platform. The
        sibling attendance project made the same choice for the same reason.
    --}}
    @vite(['resources/css/app.css'])
    @livewireStyles
</head>
<body class="h-full bg-slate-100 text-slate-900 antialiased">

    {{ $slot }}

    @livewireScripts
</body>
</html>
