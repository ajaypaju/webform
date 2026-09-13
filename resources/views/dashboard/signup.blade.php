@extends('dashboard.layout', ['title' => 'Sign up'])

@section('content')
    <h1>Sign up</h1>
    <form method="post" action="{{ route('signup') }}" class="card">
        @csrf
        <label for="company_name">Company</label>
        <input id="company_name" name="company_name" value="{{ old('company_name') }}" required maxlength="200" autofocus>
        @error('company_name')<p class="error">{{ $message }}</p>@enderror
        <label for="email">Email</label>
        <input id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required>
        @error('email')<p class="error">{{ $message }}</p>@enderror
        <label for="password">Password <span class="muted">(at least 12 characters)</span></label>
        <input id="password" name="password" type="password" autocomplete="new-password" required minlength="12">
        @error('password')<p class="error">{{ $message }}</p>@enderror
        <button type="submit">Create workspace</button>
        <p class="muted">Already have an account? <a href="{{ route('login') }}">Log in</a>.</p>
    </form>
@endsection
