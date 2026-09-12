@extends('dashboard.layout', ['title' => 'Forms'])

@section('content')
    <h1>Forms</h1>
    <form method="post" action="{{ route('dashboard.forms.store') }}" class="row">
        @csrf
        <label for="name" class="sr-only">New form name</label>
        <input id="name" name="name" placeholder="New form name" required maxlength="200">
        @error('name')<p class="error">{{ $message }}</p>@enderror
        <button type="submit">Create</button>
    </form>
    <table>
        <thead><tr><th>Name</th><th>Status</th><th>Version</th><th>Updated</th></tr></thead>
        <tbody>
        @forelse ($forms as $form)
            <tr>
                <td><a href="{{ route('dashboard.forms.show', $form) }}">{{ $form->name }}</a></td>
                <td>{{ $form->status }}</td>
                <td>{{ $form->currentVersion?->version_no ?? '—' }}</td>
                <td>{{ $form->updated_at->toDateTimeString() }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">No forms yet.</td></tr>
        @endforelse
        </tbody>
    </table>
@endsection
