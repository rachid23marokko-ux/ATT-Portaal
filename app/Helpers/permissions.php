<?php
declare(strict_types=1);

use App\Services\PermissionService;

function can(string $permission): bool
{
    return PermissionService::can($permission);
}

function require_permission(string $permission): void
{
    PermissionService::require($permission);
}

function can_brabo(): bool
{
    return PermissionService::canAccessBrabo();
}

function can_nlw(): bool
{
    return PermissionService::canAccessNlw();
}

function can_codes_gent(): bool
{
    return PermissionService::canAccessCodesGent();
}
