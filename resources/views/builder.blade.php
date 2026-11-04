<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API Integration Builder</title>
    <script>
        if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.classList.add('dark');
        }
    </script>
    <link rel="stylesheet" href="{{ $assets['css'] }}">
</head>
<body>
    <div id="app"></div>
    <script type="application/json" id="apidev-boot">@json($boot)</script>
    <script src="{{ $assets['js'] }}"></script>
</body>
</html>
