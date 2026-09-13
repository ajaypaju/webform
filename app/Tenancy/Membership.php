<?php

namespace App\Tenancy;

// The user behind a dashboard session, re-read from tenant_users on every request by DashboardTenant. Absent for
// an API-key session (operator-provisioned tenants have no user); those may do everything a verified user may.
final readonly class Membership
{
    public function __construct(public string $userId, public string $email, public string $role, public bool $verified) {}
}
