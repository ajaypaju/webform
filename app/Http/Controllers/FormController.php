<?php

namespace App\Http\Controllers;

use App\Forms\DefinitionRules;
use App\Forms\FormEditor;
use App\Http\ValidationFailed;
use App\Models\Form;
use App\Models\FormVersion;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class FormController extends Controller
{
    private const PAGE_LIMIT = 200;

    public function store(Request $request, TenantContext $tenant, DefinitionRules $rules, FormEditor $editor): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:200'], 'definition' => ['sometimes', 'array']]);
        $definition = $data['definition'] ?? ['fields' => []];
        $editor->guardDraft(strlen($request->getContent()), $definition);

        $form = Form::create([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->tenantId, 'name' => $data['name'],
            'draft' => $definition, 'status' => 'draft',
        ]);

        return response()->json($this->detail($form) + ['definition_errors' => $rules->validate($definition)], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 50), 1), self::PAGE_LIMIT);
        $query = Form::query()->with('currentVersion')->orderByDesc('created_at')->orderByDesc('id');

        if (($cursor = $request->query('cursor')) !== null) {
            $query->whereRaw('(created_at, id) < (?, ?)', $this->decodeCursor($cursor));
        }

        $forms = $query->limit($limit + 1)->get();
        $page = $forms->take($limit);

        return response()->json([
            'data' => $page->map($this->summary(...))->all(),
            'next_cursor' => $forms->count() > $limit ? $this->encodeCursor($page->last()) : null,
        ]);
    }

    public function show(Form $form): JsonResponse
    {
        return response()->json($this->detail($form->load('currentVersion')));
    }

    public function updateDraft(Request $request, Form $form, FormEditor $editor): JsonResponse
    {
        $definition = $request->validate(['definition' => ['required', 'array']])['definition'];
        $editor->guardDraft(strlen($request->getContent()), $definition);

        $errors = $editor->saveDraft($form, $definition);

        return response()->json($this->detail($form->load('currentVersion')) + ['definition_errors' => $errors]);
    }

    public function publish(Form $form, TenantContext $tenant, FormEditor $editor): JsonResponse
    {
        $version = $editor->publish($form, $tenant);

        return response()->json(['version_id' => $version->id, 'version_no' => $version->version_no], 201);
    }

    public function versions(Form $form): JsonResponse
    {
        return response()->json(['data' => $form->versions->map($this->versionSummary(...))->all()]);
    }

    private function summary(Form $form): array
    {
        return [
            'id' => $form->id, 'name' => $form->name, 'status' => $form->status,
            'current_version' => $form->currentVersion ? $this->versionSummary($form->currentVersion) : null,
            'created_at' => $form->created_at->toIso8601String(), 'updated_at' => $form->updated_at->toIso8601String(),
        ];
    }

    private function detail(Form $form): array
    {
        return $this->summary($form) + ['draft' => $form->draft];
    }

    private function versionSummary(FormVersion $version): array
    {
        return FormEditor::versionSummary($version);
    }

    private function encodeCursor(Form $form): string
    {
        return base64_encode($form->created_at->format('Y-m-d\TH:i:s.uP').'|'.$form->id);
    }

    /** @return array{0: string, 1: string} */
    private function decodeCursor(string $cursor): array
    {
        $parts = explode('|', (string) base64_decode($cursor, true), 2);

        if (count($parts) !== 2 || ! Str::isUuid($parts[1]) || strtotime($parts[0]) === false) {
            throw new ValidationFailed([['code' => 'cursor', 'field' => 'cursor']]);
        }

        return $parts;
    }
}
