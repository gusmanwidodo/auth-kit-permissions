<?php

declare(strict_types=1);

namespace Gusmanwidodo\AuthKitPermissions\Http;

use Gusmanwidodo\AuthKitPermissions\PermissionManager;
use Gusmanwidodo\AuthKitPermissions\Support\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController
{
    public function __construct(
        private readonly PermissionManager $manager,
    ) {
    }

    /**
     * Check whether a subject has an ability, optionally within a scope.
     *
     * Mirrors better-auth's hasPermission: returns { allowed: bool }.
     * Body: { subject_type, subject_id, ability, static_roles?, scope_type?, scope_id? }
     */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', 'string', 'max:255'],
            'subject_id' => ['required'],
            'ability' => ['required', 'string', 'max:255'],
            'static_roles' => ['sometimes', 'array'],
            'static_roles.*' => ['string'],
            'scope_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'scope_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $scope = isset($data['scope_type'], $data['scope_id']) && $data['scope_type'] !== null
            ? Scope::for($data['scope_type'], (int) $data['scope_id'])
            : Scope::global();

        $allowed = $this->manager->check(
            $data['subject_type'],
            $data['subject_id'],
            $data['ability'],
            $data['static_roles'] ?? [],
            $scope,
        );

        return response()->json(['allowed' => $allowed], 200);
    }
}
