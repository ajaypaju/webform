<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Not tenant data: no TenantScope, no RLS. A user may belong to several tenants (tenant_users) later.
class User extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'email', 'password_hash', 'email_verified_at'];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime'];
    }

    public function verified(): bool
    {
        return $this->email_verified_at !== null;
    }
}
