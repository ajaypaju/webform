<?php

namespace App\Http\Controllers;

use App\Forms\DefinitionRules;
use App\Forms\PublishCompat;
use App\Http\ValidationFailed;
use App\Ingest\VersionStore;
use App\Models\Form;
use App\Models\FormVersion;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FormController extends Controller
{
    private const MAX_BODY_BYTES = 262_144;

    private const MAX_FIELDS = 200;

    private const MAX_OPTIONS = 100;

    private const PAGE_LIMIT = 200;

    public function store(Request $request, TenantContext $tenant, DefinitionRules $rules): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:200'], 'definition' => ['sometimes', 'array']]);
        $definition = $data['definition'] ?? ['fields' => []];
        $this->guardDraft($request, $definition);

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

    public function updateDraft(Request $request, Form $form, DefinitionRules $rules): JsonResponse
    {
        $definition = $request->validate(['definition' => ['required', 'array']])['definition'];
        $this->guardDraft($request, $definition);

        $form->update(['draft' => $definition]);

        return response()->json($this->detail($form->load('currentVersion')) + ['definition_errors' => $rules->validate($definition)]);
    }

    public function publish(Form $form, TenantContext $tenant, DefinitionRules $rules, PublishCompat $compat, VersionStore $store): JsonResponse
    {
        return DB::transaction(function () use ($form, $tenant, $rules, $compat, $store) {
            // WHY: FOR UPDATE serializes concurrent publishes of one form, so version_no = max + 1 can't collide.
            $form = Form::whereKey($form->id)->lockForUpdate()->firstOrFail();

            if (($errors = $rules->validate($form->draft)) !== []) {
                throw ValidationFailed::codes($errors);
            }

            $priors = $form->versions()->get();

            // I5
            if (($errors = $compat->check($priors->pluck('definition')->all(), $form->draft)) !== []) {
                throw new ValidationFailed($errors);
            }

            $version = FormVersion::create([
                'id' => (string) Str::uuid7(), 'form_id' => $form->id, 'tenant_id' => $tenant->tenantId,
                'version_no' => $priors->count() + 1, 'definition' => $form->draft, 'published_at' => now(),
            ]);

            $form->update(['current_version_id' => $version->id, 'status' => 'published']);

            // WHY: the api writes the ingest store (I12) so a fresh publish is servable at once, and so the store
            // is already warm if PostgreSQL goes down before anyone reads it. Redis failure is logged, not fatal:
            // ingest's read-through repairs it.
            $summaries = $priors->push($version)->map($this->versionSummary(...))->all();
            DB::afterCommit(fn () => $store->put(
                $this->versionSummary($version) + ['form_id' => $form->id, 'tenant_id' => $tenant->tenantId, 'definition' => $version->definition],
                ['status' => 'published', 'tenant_id' => $tenant->tenantId, 'current_version_id' => $version->id, 'versions' => $summaries],
            ));

            return response()->json(['version_id' => $version->id, 'version_no' => $version->version_no], 201);
        });
    }

    public function versions(Form $form): JsonResponse
    {
        return response()->json(['data' => $form->versions->map($this->versionSummary(...))->all()]);
    }

    private function guardDraft(Request $request, array $definition): void
    {
        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            throw new ValidationFailed([['code' => 'body_too_large']], 413);
        }

        $fields = $definition['fields'] ?? [];

        if (is_array($fields) && count($fields) > self::MAX_FIELDS) {
            throw new ValidationFailed([['code' => 'too_many_fields', 'field' => 'definition']]);
        }

        foreach (is_array($fields) ? $fields : [] as $field) {
            if (is_array($field) && is_array($field['options'] ?? null) && count($field['options']) > self::MAX_OPTIONS) {
                throw new ValidationFailed([['code' => 'too_many_options', 'field' => $field['id'] ?? 'definition']]);
            }
        }
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
        return ['id' => $version->id, 'version_no' => $version->version_no, 'published_at' => $version->published_at->toIso8601String()];
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
