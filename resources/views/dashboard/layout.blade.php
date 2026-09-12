<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Dashboard' }}</title>
    @vite('resources/js/dashboard/main.js')
</head>
<body>
<header class="top">
    <a href="{{ route('dashboard.forms') }}" class="brand">webform</a>
    @if (session()->has('tenant_id'))
        <form method="post" action="{{ route('logout') }}" class="inline">
            @csrf
            <button type="submit" class="link">Log out</button>
        </form>
    @endif
</header>
<main>
    @yield('content')
</main>
</body>
</html>
