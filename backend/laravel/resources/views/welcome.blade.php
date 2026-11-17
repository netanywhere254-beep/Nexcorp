<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Nexora') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #071426; color: #e8f2ff; font-family: system-ui, sans-serif; }
        main { max-width: 680px; padding: 3rem; text-align: center; }
        .mark { color: #36c8ff; font-weight: 800; letter-spacing: .18em; }
        h1 { font-size: clamp(2rem, 6vw, 4rem); margin: .5rem 0; }
        p { color: #a9bfd7; line-height: 1.7; }
        a { color: #36c8ff; }
    </style>
</head>
<body>
<main>
    <div class="mark">NEXORA</div>
    <h1>Platform foundation is running.</h1>
    <p>This is the primary Laravel application foundation. NexISP and Loopza modules will be added here in controlled stages.</p>
    <p><a href="/health">View application health</a></p>
</main>
</body>
</html>
