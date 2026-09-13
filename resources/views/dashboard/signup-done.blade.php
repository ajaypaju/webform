{{-- chrome off: the page must not differ between a new and an existing address. --}}
@extends('dashboard.layout', ['title' => 'Check your email', 'chrome' => false])

@section('content')
    <h1>Check your email</h1>
    {{-- WHY: one page for both outcomes, so the form does not reveal which addresses have an account. --}}
    <p>If <strong>{{ $email }}</strong> was new, your workspace is ready and a verification link has been issued for it. If it already had an account, its owner has been told instead.</p>
    <p class="muted">This build has no mail transport (sending is designed, not built): the link is written to the api service log — <code>docker compose logs api | grep 'verification link'</code>.</p>
    <p><a href="{{ route('dashboard.forms') }}">Continue to the dashboard</a> · <a href="{{ route('login') }}">Log in</a></p>
@endsection
