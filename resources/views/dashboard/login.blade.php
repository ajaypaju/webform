@extends('dashboard.layout', ['title' => 'Log in'])

@section('content')
    <h1>Log in</h1>
    <p class="muted">Paste a tenant API key. It is verified once and never stored; your session holds only the tenant it belongs to.</p>
    <form method="post" action="{{ route('login') }}" class="card">
        @csrf
        <label for="api_key">API key</label>
        <input id="api_key" name="api_key" type="password" autocomplete="off" required autofocus>
        @error('api_key')<p class="error">{{ $message }}</p>@enderror
        <button type="submit">Log in</button>
    </form>
@endsection
