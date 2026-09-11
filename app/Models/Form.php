<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy(TenantScope::class)]
class Form extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['draft' => 'array'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FormVersion::class)->orderBy('version_no');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'current_version_id');
    }
}
