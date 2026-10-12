<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#534AB7">
<title>@yield('title', 'Nidaam')</title>
<link rel="icon" type="image/svg+xml" href="{{ asset('brand/nidaam-mark.svg') }}">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@600;700&family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="{{ asset('css/nidaam.css') }}?v={{ @filemtime(public_path('css/nidaam.css')) }}">
<script>try { var t = localStorage.getItem('nd_theme'); if (t === 'dark' || t === 'light') { document.documentElement.setAttribute('data-theme', t); } } catch (e) {}</script>
</head>
<body>
@yield('body')
</body>
</html>
