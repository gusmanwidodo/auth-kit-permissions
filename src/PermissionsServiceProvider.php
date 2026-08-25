<?php

declare(strict_types=1);

namespace Gusmanwidodo\AuthKitPermissions;

use Gusmanwidodo\AuthKit\AuthManager;
use Gusmanwidodo\AuthKitPermissions\Support\AccessControl;
use Illuminate\Support\ServiceProvider;

/**
 * Self-registers the permissions plugin into the Auth-Kit core registry and
 * binds the AccessControl (built from config) + PermissionManager singletons.
 */
class PermissionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/auth-kit-permissions.php', 'auth-kit-permissions');

        // Build the in-memory AccessControl from config: statement + static roles.
        $this->app->singleton(AccessControl::class, function ($app): AccessControl {
            $config = (array) $app['config']->get('auth-kit-permissions', []);

            /** @var array<string, list<string>> $statement */
            $statement = (array) ($config['statement'] ?? []);
            $ac = new AccessControl($statement);

            /** @var array<string, array<string, list<string>|string>> $roles */
            $roles = (array) ($config['roles'] ?? []);
            foreach ($roles as $name => $permissions) {
                $ac->newRole($name, (array) $permissions);
            }

            return $ac;
        });

        $this->app->singleton(PermissionManager::class, function ($app): PermissionManager {
            return new PermissionManager(
                $app->make(AccessControl::class),
                $app->make(AuthManager::class),
            );
        });

        $this->app->alias(PermissionManager::class, 'auth-kit-permissions');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/auth-kit-permissions.php' => $this->app->configPath('auth-kit-permissions.php'),
        ], 'auth-kit-permissions-config');

        // Self-register into the core registry (order-independent; the core
        // collects routes/migrations in an app->booted() callback).
        $manager = $this->app->make(AuthManager::class);
        $registry = $manager->registry();

        if (! $registry->has('permissions')) {
            $registry->register(new PermissionsPlugin());
        }
    }
}
