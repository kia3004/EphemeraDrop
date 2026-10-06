<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta http-equiv="X-Content-Type-Options" content="nosniff">
        <title>{{ $title ?? 'EphemeraDrop' }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <main class="container">
            @yield('content')
        </main>
    </body>
</html>
