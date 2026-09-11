<?php

namespace App\Forms;

final class PublishCompat
{
    private const CODE = 'type_changed';

    /**
     * A field id that has ever existed in any published version must keep its type (I5). Everything else about
     * a field may change. Semantics: conformance/README.md.
     *
     * @param  list<array<string, mixed>>  $priorVersions  every published definition of the form, oldest first
     * @param  array<string, mixed>  $draft  the definition about to be published; DefinitionRules has already passed
     * @return list<array{field: string, code: string}> in draft field order; empty when compatible
     */
    public function check(array $priorVersions, array $draft): array
    {
        $types = [];

        // I5: the first type an id ever had binds it; later versions can't have changed it, since they passed this.
        foreach ($priorVersions as $version) {
            foreach ($version['fields'] as $field) {
                $types[$field['id']] ??= $field['type'];
            }
        }

        $errors = [];

        foreach ($draft['fields'] as $field) {
            if (isset($types[$field['id']]) && $types[$field['id']] !== $field['type']) {
                $errors[] = ['field' => $field['id'], 'code' => self::CODE];
            }
        }

        return $errors;
    }
}
