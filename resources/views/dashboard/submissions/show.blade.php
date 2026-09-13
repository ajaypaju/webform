@extends('dashboard.layout', ['title' => $form->name])

@section('content')
    <p class="muted"><a href="{{ route('dashboard.submissions', $form) }}">← submissions</a></p>
    <h1>Submission</h1>

    <dl class="detail">
        <dt>Submission id</dt><dd>{{ $submission['id'] }}</dd>
        <dt>Received (UTC)</dt><dd>{{ $submission['received_at'] }}</dd>
        <dt>Version</dt><dd>v{{ $submission['version_no'] }} <span class="muted">({{ $submission['version_id'] }}) — labels below are this version's</span></dd>
    </dl>

    <h2>Answers</h2>
    <dl class="detail" data-answers>
        @foreach ($answers as $answer)
            <dt>{{ $answer['label'] }} <span class="muted">{{ $answer['id'] }}</span></dt>
            @if ($answer['answered'])
                <dd>{{ $answer['value'] }}</dd>
            @else
                <dd class="empty muted">no answer</dd>
            @endif
        @endforeach
    </dl>

    <h2>Meta</h2>
    <dl class="detail">
        <dt>IP hash</dt><dd>{{ $meta['ip_hash'] ?? '—' }}</dd>
        <dt>User agent</dt><dd>{{ $meta['user_agent'] !== '' ? $meta['user_agent'] : '—' }}</dd>
        <dt>Referer host</dt><dd>{{ $meta['referer'] ?? '—' }}</dd>
    </dl>
@endsection
