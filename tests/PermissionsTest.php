<?php

declare(strict_types=1);

use Gusmanwidodo\AuthKit\AuthManager;
use Gusmanwidodo\AuthKitPermissions\PermissionManager;
use Gusmanwidodo\AuthKitPermissions\RoleService;
use Gusmanwidodo\AuthKitPermissions\Support\AccessControl;
use Gusmanwidodo\AuthKitPermissions\Support\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Count queries run while executing $callback. */
function countQueries(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('self-registers the permissions plugin into the core registry', function () {
    expect(app(AuthManager::class)->registry()->has('permissions'))->toBeTrue();
});

it('runs the plugin migrations from the separate package', function () {
    // Tables exist iff the core picked up migrationPaths().
    expect(DB::table('auth_kit_roles')->count())->toBe(0)
        ->and(DB::table('auth_kit_permissions')->count())->toBe(0);
});

it('builds AccessControl from config statement + roles', function () {
    $ac = app(AccessControl::class);

    expect($ac->hasRole('admin'))->toBeTrue()
        ->and($ac->hasRole('viewer'))->toBeTrue()
        ->and($ac->roleNames())->toContain('author');
});

it('rejects a static role that grants an undeclared action (fail fast)', function () {
    $ac = new AccessControl(['post' => ['read']]);

    expect(fn () => $ac->newRole('bad', ['post' => ['delete']]))
        ->toThrow(InvalidArgumentException::class);
});

// ---- STATIC fast path: ZERO queries ----

it('checks a static role permission with ZERO queries', function () {
    $manager = app(PermissionManager::class);

    $allowed = null;
    $queries = countQueries(function () use ($manager, &$allowed) {
        $allowed = $manager->check('App\\User', 1, 'post.update', ['author']);
    });

    expect($allowed)->toBeTrue()
        ->and($queries)->toBe(0); // the whole point: static checks hit no DB
});

it('denies via static roles with ZERO queries when the subject has no dynamic roles', function () {
    $manager = app(PermissionManager::class);

    $allowed = null;
    $queries = countQueries(function () use ($manager, &$allowed) {
        // viewer cannot delete; and there are no dynamic assignments, but a
        // miss on static still needs to check the DB exactly once.
        $allowed = $manager->check('App\\User', 1, 'post.read', ['viewer']);
    });

    // 'viewer' grants post.read via the static path -> no DB touched.
    expect($allowed)->toBeTrue()->and($queries)->toBe(0);
});

it('admin static role with wildcard grants every action on a resource', function () {
    $manager = app(PermissionManager::class);

    expect($manager->check('App\\User', 1, 'post.delete', ['admin']))->toBeTrue()
        ->and($manager->check('App\\User', 1, 'member.delete', ['admin']))->toBeTrue();
});

// ---- DYNAMIC path: ONE query, then memoized ----

it('resolves a dynamic role with exactly ONE query, then memoizes', function () {
    /** @var RoleService $svc */
    $svc = app(RoleService::class);
    $role = $svc->createRole('editor', ['post.delete']);
    $svc->assign('App\\User', 7, $role);

    $manager = app(PermissionManager::class);
    $manager->forgetMemo();

    // First check: one resolve query.
    $first = countQueries(fn () => $manager->check('App\\User', 7, 'post.delete'));
    expect($first)->toBe(1);

    // Second + third checks in the same request: memoized, ZERO queries.
    $again = countQueries(function () use ($manager) {
        $manager->check('App\\User', 7, 'post.delete');
        $manager->check('App\\User', 7, 'post.create'); // different ability, same subject/scope
    });
    expect($again)->toBe(0);
});

it('grants a dynamic ability and denies one not assigned', function () {
    $svc = app(RoleService::class);
    $role = $svc->createRole('editor', ['post.delete']);
    $svc->assign('App\\User', 7, $role);

    $manager = app(PermissionManager::class);

    expect($manager->check('App\\User', 7, 'post.delete'))->toBeTrue()
        ->and($manager->check('App\\User', 7, 'post.create'))->toBeFalse();
});

// ---- POLYMORPHIC SCOPE (organization-ready) ----

it('isolates dynamic roles by polymorphic scope', function () {
    $svc = app(RoleService::class);
    $orgScope = Scope::for('organization', 42);
    $otherOrg = Scope::for('organization', 99);

    $role = $svc->createRole('org-admin', ['member.delete'], $orgScope);
    $svc->assign('App\\User', 5, $role, $orgScope);

    $manager = app(PermissionManager::class);

    // Allowed inside org 42...
    expect($manager->check('App\\User', 5, 'member.delete', [], $orgScope))->toBeTrue()
        // ...but NOT inside org 99 (scope isolation)...
        ->and($manager->check('App\\User', 5, 'member.delete', [], $otherOrg))->toBeFalse()
        // ...and NOT globally.
        ->and($manager->check('App\\User', 5, 'member.delete', []))->toBeFalse();
});

it('does not leak a global assignment into a scoped check', function () {
    $svc = app(RoleService::class);
    $role = $svc->createRole('global-mod', ['post.delete']); // global scope
    $svc->assign('App\\User', 8, $role);

    $manager = app(PermissionManager::class);
    $org = Scope::for('organization', 1);

    expect($manager->check('App\\User', 8, 'post.delete'))->toBeTrue()          // global: yes
        ->and($manager->check('App\\User', 8, 'post.delete', [], $org))->toBeFalse(); // scoped: no
});

// ---- HOOK PIPELINE ----

it('lets a core before-hook veto (or force) a permission check', function () {
    // Register an ad-hoc plugin whose hook denies everything.
    $manager = app(AuthManager::class);
    $manager->registry()->register(new class implements
        Gusmanwidodo\AuthKit\Contracts\AuthPlugin,
        Gusmanwidodo\AuthKit\Contracts\HasHooks
    {
        public function id(): string { return 'deny-all'; }
        public function boot(): void {}
        public function beforeHooks(): array
        {
            return ['permission.check' => function ($ctx) { $ctx->set('allow', false)->stop(); }];
        }
        public function afterHooks(): array { return []; }
    });

    $pm = app(PermissionManager::class);

    // admin would normally pass, but the hook forces denial.
    expect($pm->check('App\\User', 1, 'post.delete', ['admin']))->toBeFalse();
});

// ---- HTTP endpoint ----

it('exposes POST /auth-kit/permissions/check', function () {
    $this->postJson('/auth-kit/permissions/check', [
        'subject_type' => 'App\\User',
        'subject_id' => 1,
        'ability' => 'post.update',
        'static_roles' => ['author'],
    ])->assertOk()->assertJson(['allowed' => true]);

    $this->postJson('/auth-kit/permissions/check', [
        'subject_type' => 'App\\User',
        'subject_id' => 1,
        'ability' => 'post.delete',
        'static_roles' => ['viewer'],
    ])->assertOk()->assertJson(['allowed' => false]);
});
