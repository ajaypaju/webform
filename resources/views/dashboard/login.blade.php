@extends('dashboard.layout', ['title' => 'Log in'])

@section('content')
    <h1>Log in</h1>
    @if (session('status'))<p class="status">{{ session('status') }}</p>@endif
    @error('login')<p class="error">{{ $message }}</p>@enderror

    <div class="columns">
        <form method="post" action="{{ route('login') }}" class="card">
            @csrf
            <h2>With your account</h2>
            <label for="email">Email</label>
            <input id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required autofocus>
            @error('email')<p class="error">{{ $message }}</p>@enderror
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            @error('password')<p class="error">{{ $message }}</p>@enderror
            <button type="submit">Log in</button>
            <p class="muted">No account? <a href="{{ route('signup') }}">Sign up</a>.</p>
        </form>

        <form method="post" action="{{ route('login.key') }}" class="card">
            @csrf
            <h2>With an API key</h2>
            <p class="muted">For tenants provisioned by an operator. The key is verified once and never stored; the session holds only the tenant it belongs to.</p>
            <label for="api_key">API key</label>
            <input id="api_key" name="api_key" type="password" autocomplete="off">
            @error('api_key')<p class="error">{{ $message }}</p>@enderror
            <button type="submit">Log in with key</button>
        </form>
    </div>
@endsection
