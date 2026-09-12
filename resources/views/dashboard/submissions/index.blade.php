@extends('dashboard.layout', ['title' => $form->name])

@section('content')
    <header class="builder-head">
        <div>
            <h1>{{ $form->name }}</h1>
            <p class="muted"><a href="{{ route('dashboard.forms.show', $form) }}">builder</a> · submissions</p>
        </div>
        <div class="actions">
            <a class="button" href="{{ $exportUrl }}" data-export>Export CSV</a>
        </div>
    </header>

    <form method="get" action="{{ route('dashboard.submissions', $form) }}" class="filters">
        <label>From <input type="datetime-local" name="from" value="{{ $filters['from'] ?? '' }}" step="1"></label>
        <label>To <input type="datetime-local" name="to" value="{{ $filters['to'] ?? '' }}" step="1"></label>
        <label>Version
            <select name="version_id">
                <option value="">any</option>
                @foreach ($versions as $id => $version)
                    <option value="{{ $id }}" @selected(($filters['version_id'] ?? '') === $id)>v{{ $version['version_no'] }}</option>
                @endforeach
            </select>
        </label>
        <label>Field
            <select name="field">
                <option value="">any</option>
                @foreach ($columns as $column)
                    <option value="{{ $column['id'] }}" @selected(($filters['field'] ?? '') === $column['id'])>{{ $column['label'] }}</option>
                @endforeach
            </select>
        </label>
        <label>equals <input type="text" name="value" value="{{ $filters['value'] ?? '' }}"></label>
        <button type="submit">Filter</button>
        @if ($filters !== [])
            <a href="{{ route('dashboard.submissions', $form) }}">clear</a>
        @endif
    </form>
    @if ($filterErrors !== [])
        <ul class="errors">
            @foreach ($filterErrors as $error)
                <li>{{ $error['field'] ?? 'filter' }}: {{ ['timestamp' => 'not a date', 'uuid' => 'not a version', 'field_id' => 'not a field id', 'required' => 'a value is required', 'cursor' => 'this page link is not valid'][$error['code']] ?? $error['code'] }}</li>
            @endforeach
        </ul>
    @endif

    @if ($filterErrors !== [])
    @elseif ($rows === [])
        @if ($filters !== [])
            <p class="muted">No submissions match these filters.</p>
        @elseif ($form->status === 'published')
            <p class="muted" data-empty>No submissions yet. Share the live form: <a href="{{ $pageUrl }}" target="_blank" rel="noopener">{{ $pageUrl }}</a></p>
        @else
            <p class="muted" data-empty>No submissions yet. <a href="{{ route('dashboard.forms.show', $form) }}">Publish the form</a> to get a live page to share.</p>
        @endif
    @else
        <div class="scroll">
            <table class="submissions">
                <thead>
                <tr>
                    <th>Received</th>
                    <th>Version</th>
                    @foreach ($columns as $column)
                        <th>{{ $column['label'] }}</th>
                    @endforeach
                </tr>
                </thead>
                <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td><a class="row-link" href="{{ route('dashboard.submissions.show', ['form' => $form->id, 'submission' => $row['id']]) }}">{{ $row['received_at'] }}</a></td>
                        <td>v{{ $row['version_no'] }}</td>
                        @foreach ($row['cells'] as $cell)
                            @if ($cell['state'] === 'absent')
                                <td class="absent" title="Not a field in this submission's version">n/a</td>
                            @else
                                <td class="{{ $cell['state'] }}">{{ $cell['value'] }}</td>
                            @endif
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <nav class="pager" aria-label="Pages">
            @if ($previousUrl)<a href="{{ $previousUrl }}" rel="prev">← Newer</a>@endif
            <span class="muted">showing {{ count($rows) }} of many</span>
            @if ($nextUrl)<a href="{{ $nextUrl }}" rel="next">Older →</a>@endif
        </nav>
    @endif
@endsection
