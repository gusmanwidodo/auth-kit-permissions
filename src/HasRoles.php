<?php

declare(strict_types=1);

namespace Gusmanwidodo\AuthKitPermissions;

use Gusmanwidodo\AuthKitPermissions\Support\Scope;

/**
 * Convenience trait for subjects (typically the User model) that hold roles.
 *
 * The model tracks its STATIC role names (fast, in-memory) via a property or
 * accessor; dynamic roles live in the DB and are resolved by PermissionManager.
 * Override staticRoleNames() to source static roles from wherever you keep them
 * (a column, a cast, a relationship, etc.).
 */
trait HasRoles
{
    /**
     * Static role names this subject holds. Override to fit your storage.
     *
     * @return list<string>
     */
    public function staticRoleNames(): array
    {
        // Default: a `roles` attribute holding an array or comma string.
        $roles = $this->getAttribute('roles');

        if (is_array($roles)) {
            return array_values($roles);
        }

        if (is_string($roles) && $roles !== '') {
            return array_map('trim', explode(',', $roles));
        }

        return [];
    }

    /** Stable polymorphic subject type for this model. */
    public function subjectType(): string
    {
        return static::class;
    }

    /**
     * Check a permission ("resource.action") in an optional scope, using both
     * the static fast path and dynamic DB roles.
     */
    public function can(string $ability, mixed $arguments = [], ?Scope $scope = null): bool
    {
        // Preserve Laravel's Gate contract when called the framework way, but
        // add our scope-aware hybrid check when given an ability string.
        if (! is_string($ability)) {
            return parent::can($ability, $arguments);
        }

        /** @var PermissionManager $manager */
        $manager = app(PermissionManager::class);

        return $manager->check(
            $this->subjectType(),
            (int) $this->getKey(),
            $ability,
            $this->staticRoleNames(),
            $scope,
        );
    }
}
