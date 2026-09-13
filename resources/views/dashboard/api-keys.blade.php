@extends('dashboard.layout', ['title' => 'API keys'])

@section('content')
    <h1>API keys</h1>
    <p class="muted">Bearer tokens for <code>/v1</code>. A key is shown once, at creation; only its hash is stored. Revoking takes effect on the next request.</p>

    @if (session('new_key'))
        <div class="card notice" data-new-key>
            <p><strong>New key — copy it now; it will not be shown again.</strong></p>
            <code class="key">{{ session('new_key') }}</code>
        </div>
    @endif
    @if (session('status'))<p class="status">{{ session('status') }}</p>@endif
    @error('revoke')<p class="error">{{ $message }}</p>@enderror

    <form method="post" action="{{ route('dashboard.api-keys.store') }}" class="row">
        @csrf
        <button type="submit">Create key</button>
    </form>

    <table>
        <thead><tr><th>Key</th><th>Created (UTC)</th><th>Last used (UTC)</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @foreach ($keys as $key)
            <tr>
                <td><code>{{ $key->prefix !== '' ? $key->prefix.'…' : '(provisioned before prefixes)' }}</code></td>
                <td>{{ \Illuminate\Support\Carbon::parse($key->created_at)->utc()->toDateTimeString() }}</td>
                <td>{{ $key->last_used_at ? \Illuminate\Support\Carbon::parse($key->last_used_at)->utc()->toDateTimeString() : 'never' }}</td>
                @if ($key->revoked_at)
                    <td class="muted">revoked {{ \Illuminate\Support\Carbon::parse($key->revoked_at)->utc()->toDateTimeString() }}</td>
                    <td></td>
                @else
                    <td>live</td>
                    <td>
                        <form method="post" action="{{ route('dashboard.api-keys.revoke', $key->id) }}" class="inline">
                            @csrf
                            <button type="submit" class="link">Revoke</button>
                        </form>
                    </td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </table>
@endsection
