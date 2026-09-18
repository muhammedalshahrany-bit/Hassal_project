<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationMembership;

class TenantContext
{
    protected static ?Organization $organization = null;
    protected static ?OrganizationMembership $membership = null;

    public static function set(Organization $organization, ?OrganizationMembership $membership = null): void
    {
        static::$organization = $organization;
        static::$membership = $membership;
    }

    public static function get(): ?Organization
    {
        return static::$organization;
    }

    public static function id(): ?int
    {
        return static::$organization?->id;
    }

    public static function membership(): ?OrganizationMembership
    {
        return static::$membership;
    }

    public static function role(): ?string
    {
        return static::$membership?->role;
    }

    public static function clear(): void
    {
        static::$organization = null;
        static::$membership = null;
    }
}
