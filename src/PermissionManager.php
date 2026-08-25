<?php

declare(strict_types=1);

namespace Gusmanwidodo\AuthKitPermissions;

use Gusmanwidodo\AuthKit\AuthManager;
use Gusmanwidodo\AuthKitPermissions\Support\AccessControl;
use Gusmanwidodo\AuthKitPermissions\Support\Scope;
use Illuminate\Support\Facades\DB;

/**
 * Unified access-control API over the hybrid (static + dynamic) model.
 *
 * check() consults, in order:
 *   1. STATIC roles held by the subject (in-memory, ZERO queries).
 *   2. DYNAMIC roles/permissions from the DB, resolved with ONE query per
 *      (subject, scope) and memoized for the rest of the request.
 *
 * Callers never care which layer answered. Every check runs the core
 * `before:permission.check` hook first, so other plugins may observe or veto.
 */
class PermissionManager
{
    /**
     * Per-request memoization of resolved dynamic abilities.
     *
     * @var array<string, array<string, true>> memoKey => set of "resource.action"
     */
    private array $dynamicMemo = [];

    public function __construct(
        private readonly AccessControl $ac,
        private readonly AuthManager $auth,
    ) {
    }

    /** The static access-control primitive (statement + static roles). */
    public function accessControl(): AccessControl
    {
        return $this->ac;
    }

    /**
     * Does the subject (identified by a stable string id, e.g. "App\\User:1")
     * holding `$staticRoles` have `$ability` (== "resource.action") in `$scope`?
     *
     * @param list<string> $staticRoles static role names the subject holds
     */
    public function check(
        string $subjectType,
        int|string $subjectId,
        string $ability,
        array $staticRoles = [],
        ?Scope $scope = null,
    ): bool {
        $scope ??= Scope::global();

        [$resource, $action] = $this->splitAbility($ability);

        // Run the core hook pipeline. A hook may force the answer via 'allow'.
        $context = $this->auth->runBefore('permission.check', [
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'ability' => $ability,
            'scope' => $scope->key(),
            'allow' => null,
        ]);

        $forced = $context->get('allow');
        if ($forced !== null) {
            return (bool) $forced;
        }

        // 1) STATIC fast path — pure in-memory, zero queries.
        if ($this->ac->roleCan($staticRoles, $resource, $action)) {
            return true;
        }

        // 2) DYNAMIC path — one query per (subject, scope), memoized.
        $abilities = $this->resolveDynamic($subjectType, (string) $subjectId, $scope);

        return isset($abilities[$ability]);
    }

    /**
     * Resolve the set of dynamic abilities a subject has in a scope.
     *
     * Executes exactly ONE query the first time for a given (subject, scope),
     * then serves subsequent calls in the same request from memory.
     *
     * @return array<string, true> set of "resource.action"
     */
    public function resolveDynamic(string $subjectType, string $subjectId, Scope $scope): array
    {
        $memoKey = $subjectType . '#' . $subjectId . '@' . $scope->key();

        if (isset($this->dynamicMemo[$memoKey])) {
            return $this->dynamicMemo[$memoKey];
        }

        // Single joined query: assignments -> roles -> permissions, filtered by
        // subject and scope. Scope match is exact (including the null/global
        // case) so a global assignment does not leak into a scoped check and
        // vice versa.
        $rows = DB::table('auth_kit_role_assignments as a')
            ->join('auth_kit_roles as r', 'r.id', '=', 'a.role_id')
            ->join('auth_kit_role_permission as rp', 'rp.role_id', '=', 'r.id')
            ->join('auth_kit_permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('a.subject_type', $subjectType)
            ->where('a.subject_id', $subjectId)
            ->when(
                $scope->isGlobal(),
                fn ($q) => $q->whereNull('a.scope_type')->whereNull('a.scope_id'),
                fn ($q) => $q->where('a.scope_type', $scope->type)->where('a.scope_id', $scope->id),
            )
            ->get(['p.resource', 'p.action']);

        $set = [];
        foreach ($rows as $row) {
            $set[$row->resource . '.' . $row->action] = true;
        }

        return $this->dynamicMemo[$memoKey] = $set;
    }

    /** Clear the per-request memo (e.g. after mutating assignments in-request). */
    public function forgetMemo(): void
    {
        $this->dynamicMemo = [];
    }

    /**
     * @return array{0: string, 1: string} [resource, action]
     */
    private function splitAbility(string $ability): array
    {
        $pos = strpos($ability, '.');

        if ($pos === false) {
            return [$ability, ''];
        }

        return [substr($ability, 0, $pos), substr($ability, $pos + 1)];
    }
}
