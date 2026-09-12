<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Form</title>
    @vite('resources/js/form/main.js')
</head>
<body>
<main>
    <noscript><p>This form needs JavaScript to be submitted.</p></noscript>

    {{-- I9: a data block is not executed, so the CSP allows it; @json hex-escapes < > & ' " so nothing can close it. --}}
    <script type="application/json" id="form-definition">@json($version['definition'])</script>

    <form method="post" action="/v1/forms/{{ $formId }}/versions/{{ $version['id'] }}/submissions" novalidate
          data-form-id="{{ $formId }}" data-version-id="{{ $version['id'] }}" data-render-token="{{ $token }}">
        @foreach ($fields as $field)
            @php($id = $field['id'])
            @php($required = $field['required'] ?? false)
            @php($help = $field['help_text'] ?? null)
            <fieldset data-field="{{ $id }}" @unless (isset($visible[$id])) hidden @endunless>
                @switch($field['type'])
                    @case('text')
                    @case('email')
                    @case('number')
                    @case('date')
                        <label for="f-{{ $id }}" @if ($required) class="required" @endif>{{ $field['label'] }}</label>
                        <input id="f-{{ $id }}" name="{{ $id }}" type="{{ $field['type'] }}" @if ($field['type'] === 'number') step="any" @endif @if ($required) required @endif>
                        @break
                    @case('select')
                    @case('multiselect')
                        <label for="f-{{ $id }}" @if ($required) class="required" @endif>{{ $field['label'] }}</label>
                        <select id="f-{{ $id }}" name="{{ $id }}" @if ($field['type'] === 'multiselect') multiple @endif @if ($required) required @endif>
                            @if ($field['type'] === 'select')<option value="">Choose…</option>@endif
                            @foreach ($field['options'] as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                        @break
                    @case('radio')
                        <legend @if ($required) class="required" @endif>{{ $field['label'] }}</legend>
                        @foreach ($field['options'] as $option)
                            <label><input type="radio" name="{{ $id }}" value="{{ $option['value'] }}"> {{ $option['label'] }}</label>
                        @endforeach
                        @break
                    @case('checkbox')
                        <label @if ($required) class="required" @endif><input type="checkbox" name="{{ $id }}" value="1"> {{ $field['label'] }}</label>
                        @break
                @endswitch
                @if ($help)<p class="help">{{ $help }}</p>@endif
                <p class="error" data-error-for="{{ $id }}" aria-live="polite"></p>
            </fieldset>
        @endforeach
        <button type="submit">Submit</button>
    </form>
</main>
</body>
</html>
