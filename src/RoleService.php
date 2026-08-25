<?php

declare(strict_types=1);

namespace Gusmanwidodo\AuthKitPermissions;

use Gusmanwidodo\AuthKitPermissions\Models\Permission;
use Gusmanwidodo\AuthKitPermissions\Models\Role;
use Gusmanwidodo\AuthKitPermissions\Support\Scope;

/**
 * Manage dynamic (database) roles, their permissions, and assignments — the
 * runtime-defined layer. Static roles come from config and need no management.
 *
 * The organization plugin will call these with a Scope::for('organization', id)
 * to create/assign roles that live only inside that organization.
 */
class RoleService
{
    public function __construct(
        private readonly PermissionManager $manager,
    ) {
    }

    /** Ensure a permission (resource.action) exists, returning it. */
    public function definePermission(string $resource, string $action): Permission
    {
        return Permission::query()->firstOrCreate(compact('resource', 'action'));
    }

    /**
     * Create (or fetch) a dynamic role in a scope and set its permissions.
     *
     * @param list<string> $abilities list of "resource.action"
     */
    public function createRole(string $name, array $abilities = [], ?Scope $scope = null): Role
    {
        $scope ??= Scope::global();

        $role = Role::query()->firstOrCreate([
            'name' => $name,
            'scope_type' => $scope->type,
            'scope_id' => $scope->id,
        ]);

        $permissionIds = [];
        foreach ($abilities as $ability) {
            [$resource, $action] = array_pad(explode('.', $ability, 2), 2, '');
            $permissionIds[] = $this->definePermission($resource, $action)->id;
        }

        $role->permissions()->sync($permissionIds);
        $this->manager->forgetMemo();

        return $role;
    }

    /** Assign a dynamic role to a subject within a scope. */
    public function assign(string $subjectType, int|string $subjectId, Role $role, ?Scope $scope = null): void
    {
        $scope ??= Scope::global();

        \Illuminate\Support\Facades\DB::table('auth_kit_role_assignments')->updateOrInsert(
            [
                'subject_type' => $subjectType,
                'subject_id' => (string) $subjectId,
                'role_id' => $role->id,
                'scope_type' => $scope->type,
                'scope_id' => $scope->id,
            ],
            ['updated_at' => now(), 'created_at' => now()],
        );

        $this->manager->forgetMemo();
    }

    /** Remove a dynamic role assignment from a subject within a scope. */
    public function unassign(string $subjectType, int|string $subjectId, Role $role, ?Scope $scope = null): void
    {
        $scope ??= Scope::global();

        \Illuminate\Support\Facades\DB::table('auth_kit_role_assignments')
            ->where('subject_type', $subjectType)
            ->where('subject_id', (string) $subjectId)
            ->where('role_id', $role->id)
            ->where('scope_type', $scope->type)
            ->where('scope_id', $scope->id)
            ->delete();

        $this->manager->forgetMemo();
    }
}
