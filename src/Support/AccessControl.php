<?php

declare(strict_types=1);

namespace Gusmanwidodo\AuthKitPermissions\Support;

use InvalidArgumentException;

/**
 * In-memory access-control primitive, modeled on better-auth's
 * `createAccessControl`.
 *
 * A "statement" maps each resource to the actions defined on it, e.g.
 *   ['post' => ['create', 'update', 'delete'], 'comment' => ['create']]
 *
 * Roles are named subsets of that statement. A permission check against a role
 * is pure in-memory set logic — ZERO database queries. This is the fast path
 * and the primary performance advantage over a DB-only model.
 */
final class AccessControl
{
    /** @var array<string, list<string>> resource => actions */
    private array $statement;

    /** @var array<string, array<string, list<string>>> roleName => (resource => actions) */
    private array $roles = [];

    /**
     * @param array<string, list<string>> $statement
     */
    public function __construct(array $statement)
    {
        foreach ($statement as $resource => $actions) {
            if (! is_string($resource) || ! is_array($actions)) {
                throw new InvalidArgumentException('Statement must be array<string, list<string>>.');
            }
        }

        $this->statement = $statement;
    }

    /**
     * Define a role as a subset of the statement.
     *
     * Passing '*' as the actions for a resource grants every action defined for
     * that resource in the statement. Unknown resources/actions throw so typos
     * fail fast at boot rather than silently denying at runtime.
     *
     * @param array<string, list<string>|string> $permissions resource => actions | '*'
     */
    public function newRole(string $name, array $permissions): self
    {
        $resolved = [];

        foreach ($permissions as $resource => $actions) {
            if (! isset($this->statement[$resource])) {
                throw new InvalidArgumentException("Unknown resource [{$resource}] in role [{$name}].");
            }

            if ($actions === '*') {
                $resolved[$resource] = $this->statement[$resource];

                continue;
            }

            foreach ((array) $actions as $action) {
                if (! in_array($action, $this->statement[$resource], true)) {
                    throw new InvalidArgumentException(
                        "Unknown action [{$action}] on resource [{$resource}] in role [{$name}]."
                    );
                }
            }

            $resolved[$resource] = array_values((array) $actions);
        }

        $this->roles[$name] = $resolved;

        return $this;
    }

    /** Whether a static role with this name is defined. */
    public function hasRole(string $name): bool
    {
        return isset($this->roles[$name]);
    }

    /**
     * Does ANY of the given roles grant `$action` on `$resource`?
     *
     * Pure in-memory check — no I/O. This is what makes the common case fast.
     *
     * @param list<string> $roles
     */
    public function roleCan(array $roles, string $resource, string $action): bool
    {
        foreach ($roles as $role) {
            $grants = $this->roles[$role][$resource] ?? null;

            if ($grants !== null && in_array($action, $grants, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The full permission set for a role (resource => actions), or [] if unknown.
     *
     * @return array<string, list<string>>
     */
    public function permissionsFor(string $role): array
    {
        return $this->roles[$role] ?? [];
    }

    /** @return array<string, list<string>> the underlying statement */
    public function statement(): array
    {
        return $this->statement;
    }

    /** @return list<string> names of all defined static roles */
    public function roleNames(): array
    {
        return array_keys($this->roles);
    }
}
