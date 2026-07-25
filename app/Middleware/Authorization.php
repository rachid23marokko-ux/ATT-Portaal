<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Services\PermissionService;

final class Authorization
{
    public static function require(string $permission): void
    {
        Auth::requireLogin();
        PermissionService::require($permission);
    }

    public static function allows(string $permission): bool
    {
        Auth::requireLogin();

        return PermissionService::can($permission);
    }
}
