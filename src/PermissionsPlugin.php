<?php

declare(strict_types=1);

namespace Gusmanwidodo\AuthKitPermissions;

use Gusmanwidodo\AuthKit\Contracts\AuthPlugin;
use Gusmanwidodo\AuthKit\Contracts\HasRoutes;
use Gusmanwidodo\AuthKit\Contracts\HasSchema;

/**
 * Roles & permissions plugin: hybrid static (zero-query) + dynamic (DB) access
 * control with polymorphic scoping. Organization-ready.
 *
 * Implements the schema + routes surface. The runtime check logic lives in
 * PermissionManager (resolved from the container); this class exists to
 * self-register the plugin and expose its migrations/routes to the core.
 */
class PermissionsPlugin implements AuthPlugin, HasSchema, HasRoutes
{
    public function id(): string
    {
        return 'permissions';
    }

    public function boot(): void
    {
        // Nothing to wire at container-time; the manager is a singleton.
    }

    public function migrationPaths(): array
    {
        return [__DIR__ . '/../database/migrations'];
    }

    public function routePaths(): array
    {
        return [__DIR__ . '/../routes/permissions.php'];
    }
}
