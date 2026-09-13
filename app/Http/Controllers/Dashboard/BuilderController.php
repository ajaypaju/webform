<?php

namespace App\Http\Controllers\Dashboard;

use App\Forms\DefinitionRules;
use App\Forms\FormEditor;
use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// Server-rendered builder. Every mutation goes through FormEditor — the same code the /v1 API runs — so the
// dashboard can't publish anything the API would refuse.
final class BuilderController extends Controller
{
    public function __construct(private FormEditor $editor, private DefinitionRules $rules) {}

    public function index(): View
    {
        return view('dashboard.forms', ['forms' => Form::query()->with('currentVersion')->orderByDesc('created_at')->orderByDesc('id')->get()]);
    }

    public function store(Request $request, TenantContext $tenant): RedirectResponse
    {
        $name = $request->validate(['name' => ['required', 'string', 'max:200']])['name'];
        $form = Form::create(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->tenantId, 'name' => $name, 'draft' => ['fields' => []], 'status' => 'draft']);

        return redirect()->route('dashboard.forms.show', $form);
    }

    public function show(Form $form): View
    {
        $form->load('currentVersion');

        return view('dashboard.builder', ['form' => $form, 'data' => $this->state($form)]);
    }

    public function saveDraft(Request $request, Form $form): JsonResponse
    {
        $definition = $request->validate(['definition' => ['required', 'array']])['definition'];
        $definition['fields'] = $this->assignIds($form, is_array($definition['fields'] ?? null) ? $definition['fields'] : []);
        $this->editor->guardDraft(strlen($request->getContent()), $definition);

        $errors = $this->editor->saveDraft($form, $definition);

        return response()->json(['definition' => $definition, 'definition_errors' => $errors, 'field_errors' => $this->fieldErrors($definition)]);
    }

    public function publish(Request $request, Form $form, TenantContext $tenant): JsonResponse
    {
        if (! ApiKeyController::mayManage($request)) {
            return response()->json(['error' => 'email_unverified'], 403);
        }

        $version = $this->editor->publish($form, $tenant);

        return response()->json(['version_id' => $version->id, 'version_no' => $version->version_no, 'page_url' => self::pageUrl($form)], 201);
    }

    /** Everything the builder page needs, embedded as a JSON data block (I9). */
    private function state(Form $form): array
    {
        $draft = $form->draft;

        return [
            'form' => [
                'id' => $form->id, 'name' => $form->name, 'status' => $form->status,
                'current_version' => $form->currentVersion ? FormEditor::versionSummary($form->currentVersion) : null,
                'page_url' => self::pageUrl($form),
            ],
            'draft' => $draft,
            'definition_errors' => $this->rules->validate($draft),
            'field_errors' => $this->fieldErrors($draft),
            // Prior definitions let the page explain a PublishCompat refusal ("published as email in v1").
            'versions' => $form->versions()->get()->map(fn ($v) => FormEditor::versionSummary($v) + ['definition' => $v->definition])->all(),
            'csrf' => csrf_token(),
        ];
    }

    /**
     * I5: ids are minted here, once, from the label; the builder shows them read-only and sends them back unchanged.
     * A new id never collides with any id the form has ever used, published or drafted.
     */
    private function assignIds(Form $form, array $fields): array
    {
        $taken = array_fill_keys(array_filter(array_column($fields, 'id'), 'is_string'), true);

        foreach ($form->versions()->pluck('definition') as $definition) {
            foreach ($definition['fields'] ?? [] as $field) {
                $taken[$field['id']] = true;
            }
        }

        foreach ($fields as $i => $field) {
            if (! is_array($field) || is_string($field['id'] ?? null)) {
                continue;
            }

            $base = Str::limit(Str::slug((string) ($field['label'] ?? ''), '_'), 32, '') ?: 'field';
            $id = $base;

            for ($n = 2; isset($taken[$id]); $n++) {
                $id = "{$base}_{$n}";
            }

            $taken[$id] = true;
            $fields[$i]['id'] = $id;
        }

        return array_values($fields);
    }

    /**
     * DefinitionRules returns codes in field order without attribution. Validating the definition one field
     * longer each time and diffing attributes each new code to the field that introduced it, cross-field checks
     * (duplicate ids, visible_if ordering) included.
     *
     * @return array<string, list<string>> field id => codes
     */
    private function fieldErrors(array $definition): array
    {
        $fields = is_array($definition['fields'] ?? null) ? array_values($definition['fields']) : [];
        $errors = [];
        $previous = [];

        foreach ($fields as $i => $field) {
            $current = $this->rules->validate(['fields' => array_slice($fields, 0, $i + 1)]);
            $new = $current;

            foreach ($previous as $code) {
                if (($k = array_search($code, $new, true)) !== false) {
                    unset($new[$k]);
                }
            }

            if ($new !== [] && is_array($field) && is_string($field['id'] ?? null)) {
                $errors[$field['id']] = array_values($new);
            }

            $previous = $current;
        }

        return $errors;
    }

    private static function pageUrl(Form $form): string
    {
        return rtrim(config('app.public_url'), '/')."/f/{$form->id}";
    }
}
