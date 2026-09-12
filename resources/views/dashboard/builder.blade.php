@extends('dashboard.layout', ['title' => $form->name])

@section('content')
    {{-- I9: a data block is never executed; @json hex-escapes < > & ' " so tenant content can't close it. --}}
    <script type="application/json" id="builder-data">@json($data)</script>

    <div class="builder" data-builder>
        <header class="builder-head">
            <div>
                <h1>{{ $form->name }}</h1>
                <p class="muted">
                    <span data-status>{{ $form->status }}</span>
                    @if ($form->currentVersion)
                        · v<span data-version-no>{{ $form->currentVersion->version_no }}</span>
                        · <a data-page-link href="{{ $data['form']['page_url'] }}" target="_blank" rel="noopener">open live form</a>
                    @else
                        · <span data-version-no>not published</span> <a data-page-link hidden href="{{ $data['form']['page_url'] }}" target="_blank" rel="noopener">open live form</a>
                    @endif
                </p>
            </div>
            <div class="actions">
                <button type="button" data-action="save">Save draft</button>
                <button type="button" data-action="publish" class="primary">Publish</button>
            </div>
        </header>
        <p class="status" data-message aria-live="polite"></p>
        <ul class="errors" data-global-errors></ul>

        <div class="columns">
            <section class="editor">
                <h2>Fields</h2>
                <div data-fields></div>
                <div class="add">
                    <label for="add-type" class="sr-only">Field type</label>
                    <select id="add-type" data-add-type>
                        @foreach (['text', 'email', 'number', 'select', 'multiselect', 'radio', 'checkbox', 'date'] as $type)
                            <option value="{{ $type }}">{{ $type }}</option>
                        @endforeach
                    </select>
                    <button type="button" data-action="add">Add field</button>
                </div>
            </section>
            <section class="preview">
                <h2>Preview</h2>
                <p class="muted">Renders the draft with the public page's own code; nothing is sent.</p>
                <form data-preview data-form-id="{{ $form->id }}" novalidate></form>
            </section>
        </div>
    </div>
@endsection
