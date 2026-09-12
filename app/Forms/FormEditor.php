<?php

namespace App\Forms;

use App\Http\ValidationFailed;
use App\Ingest\VersionStore;
use App\Models\Form;
use App\Models\FormVersion;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Draft and publish rules shared by the /v1 API and the dashboard, so neither can drift from the other.
final class FormEditor
{
    public const MAX_BODY_BYTES = 262_144;

    public const MAX_FIELDS = 200;

    public const MAX_OPTIONS = 100;

    public function __construct(private DefinitionRules $rules, private PublishCompat $compat, private VersionStore $store) {}

    /** @throws ValidationFailed 413 on body size, 422 on field/option caps */
    public function guardDraft(int $bodyBytes, array $definition): void
    {
        if ($bodyBytes > self::MAX_BODY_BYTES) {
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

    /** Saves even an invalid draft; returns the advisory DefinitionRules codes. */
    public function saveDraft(Form $form, array $definition): array
    {
        $form->update(['draft' => $definition]);

        return $this->rules->validate($definition);
    }

    /**
     * One transaction: lock the form, require a valid and compatible definition (I5), create the version, pre-warm
     * the ingest store after commit.
     *
     * @throws ValidationFailed 422 with DefinitionRules codes or PublishCompat {field, code} entries
     */
    public function publish(Form $form, TenantContext $tenant): FormVersion
    {
        return DB::transaction(function () use ($form, $tenant) {
            // WHY: FOR UPDATE serializes concurrent publishes of one form, so version_no = max + 1 can't collide.
            $form = Form::whereKey($form->id)->lockForUpdate()->firstOrFail();

            if (($errors = $this->rules->validate($form->draft)) !== []) {
                throw ValidationFailed::codes($errors);
            }

            $priors = $form->versions()->get();

            // I5
            if (($errors = $this->compat->check($priors->pluck('definition')->all(), $form->draft)) !== []) {
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
            $summaries = $priors->push($version)->map(self::versionSummary(...))->all();
            DB::afterCommit(fn () => $this->store->put(
                self::versionSummary($version) + ['form_id' => $form->id, 'tenant_id' => $tenant->tenantId, 'definition' => $version->definition],
                ['status' => 'published', 'tenant_id' => $tenant->tenantId, 'current_version_id' => $version->id, 'versions' => $summaries],
            ));

            return $version;
        });
    }

    public static function versionSummary(FormVersion $version): array
    {
        return ['id' => $version->id, 'version_no' => $version->version_no, 'published_at' => $version->published_at->toIso8601String()];
    }
}
