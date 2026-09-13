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
@php($membership = request()->attributes->get('membership'))
<header class="top">
    <a href="{{ route('dashboard.forms') }}" class="brand">webform</a>
    @if (session()->has('tenant_id') && ($chrome ?? true))
        <nav class="nav">
            <a href="{{ route('dashboard.forms') }}">Forms</a>
            <a href="{{ route('dashboard.api-keys') }}">API keys</a>
            @if ($membership)<span class="muted">{{ $membership->email }}</span>@endif
            <form method="post" action="{{ route('logout') }}" class="inline">
                @csrf
                <button type="submit" class="link">Log out</button>
            </form>
        </nav>
    @endif
</header>
<main>
    @if ($membership && ! $membership->verified)
        <div class="notice" data-unverified>
            <p>Your email address is not verified yet: you can build forms, but not publish them or create API keys.
                This build sends no mail — the verification link is in the api service log
                (<code>docker compose logs api | grep 'verification link'</code>).</p>
            <form method="post" action="{{ route('verify.resend') }}" class="inline">
                @csrf
                <button type="submit" class="link">Write a new link to the log</button>
            </form>
        </div>
    @endif
    @if (session('status') && ! request()->routeIs('login', 'dashboard.api-keys'))<p class="status">{{ session('status') }}</p>@endif
    @yield('content')
</main>
</body>
</html>
