<?php

namespace App\Models\Scopes;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use RuntimeException;

final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // I11: fail closed. A control-plane query without a tenant context is a bug, never "all tenants".
        if (! app()->bound(TenantContext::class)) {
            throw new RuntimeException('No tenant context bound; control-plane queries need AuthenticateApiKey (I11).');
        }

        $builder->where($model->qualifyColumn('tenant_id'), app(TenantContext::class)->tenantId);
    }
}
