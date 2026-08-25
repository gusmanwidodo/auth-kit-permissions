# Auth-Kit Permissions

High-performance **roles & permissions** plugin for
[Auth-Kit](https://github.com/gusmanwidodo/auth-kit). A hybrid access-control
model — **static roles** (in-memory, zero-query) + **dynamic roles** (database)
— with **polymorphic scoping** that is ready for the upcoming
`auth-kit-organization` plugin.

[![Tests](https://github.com/gusmanwidodo/auth-kit-permissions/actions/workflows/tests.yml/badge.svg)](https://github.com/gusmanwidodo/auth-kit-permissions/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

## Why another permissions package?

`spatie/laravel-permission` is excellent and battle-tested, but every permission
check ultimately resolves against the database (mitigated by a global cache).
For the overwhelmingly common case — a role defined at deploy time — that work
is avoidable. Auth-Kit Permissions answers those checks **in memory, with zero
queries**, and only touches the database for genuinely *dynamic*, runtime-defined
roles (which it resolves in a single, memoized query).

The model mirrors [better-auth's Access Control](https://better-auth.com/docs/plugins/organization#access-control):
a **statement** (`resource => actions`) → **roles** (subsets of the statement) →
**checks**.

## Benchmark

Real micro-benchmark (same process, same in-memory SQLite, spatie cache warm,
30,000 iterations, PHP 8.3). Reproduce with `composer bench`.

| Scenario | checks/sec | ns/check |
|----------|-----------:|---------:|
| **Ours — static role check (config)** | ~2,582,000 | ~387 |
| Spatie — `hasPermissionTo` (cache warm) | ~39,800 | ~25,115 |
| **Ours — dynamic check (memoized, in-request)** | ~2,188,000 | ~457 |

→ **~65× faster** than spatie on the common (static-role) check. Absolute numbers
vary by machine; the *ratio* and the *query count* are the point:

- static check = **0 queries**
- first dynamic check per (subject, scope) = **1 query**
- repeated checks in the same request = **0 additional queries** (memoized)

These are asserted in the test suite, not just claimed.

## Requirements

- PHP `^8.3`
- `gusmanwidodo/auth-kit` `^0.1`
- Laravel 12

## Installation

```bash
composer require gusmanwidodo/auth-kit-permissions
php artisan migrate
php artisan vendor:publish --tag=auth-kit-permissions-config
```

## Defining static roles (the fast path)

In `config/auth-kit-permissions.php`, declare a **statement** and **roles**:

```php
'statement' => [
    'post'   => ['create', 'read', 'update', 'delete'],
    'member' => ['create', 'update', 'delete'],
],

'roles' => [
    'admin'  => ['post' => '*', 'member' => '*'], // '*' = every action
    'author' => ['post' => ['create', 'read', 'update']],
    'viewer' => ['post' => ['read']],
],
```

Roles can only grant actions declared in the statement — typos throw at boot, so
you never silently deny in production.

## Checking permissions

```php
use Gusmanwidodo\AuthKitPermissions\PermissionManager;
use Gusmanwidodo\AuthKitPermissions\Support\Scope;

$pm = app(PermissionManager::class);

// Static, in-memory, ZERO queries:
$pm->check('App\\User', $user->id, 'post.update', staticRoles: ['author']); // true

// Dynamic (DB) role, resolved once then memoized:
$pm->check('App\\User', $user->id, 'post.delete'); // 1 query, then 0
```

Or use the `HasRoles` trait on your User model:

```php
use Gusmanwidodo\AuthKitPermissions\HasRoles;

class User extends Authenticatable
{
    use HasRoles; // reads static roles from a `roles` attribute by default
}

$user->can('post.update'); // hybrid static + dynamic check
```

### HTTP endpoint

Mirrors better-auth's `hasPermission`:

| Method | URI | Body | Returns |
|--------|-----|------|---------|
| POST | `/auth-kit/permissions/check` | `{ subject_type, subject_id, ability, static_roles?, scope_type?, scope_id? }` | `{ allowed: bool }` |

## Dynamic roles

Runtime-defined roles live in the database and are managed via `RoleService`:

```php
use Gusmanwidodo\AuthKitPermissions\RoleService;

$svc  = app(RoleService::class);
$role = $svc->createRole('editor', ['post.delete']); // global scope
$svc->assign('App\\User', $user->id, $role);
```

## Scoping & organization compatibility

Every assignment and check carries an optional **polymorphic scope**
(`scope_type` + `scope_id`). `null` = global.

```php
use Gusmanwidodo\AuthKitPermissions\Support\Scope;

$org = Scope::for('organization', $organization->id);

$role = $svc->createRole('org-admin', ['member.delete'], $org);
$svc->assign('App\\User', $user->id, $role, $org);

$pm->check('App\\User', $user->id, 'member.delete', scope: $org); // true inside org only
```

**This is the bridge to the planned `auth-kit-organization` plugin.** When it
ships, it will simply:

1. build scopes with `Scope::for('organization', $org->id)`,
2. create/assign roles within that scope via `RoleService`,
3. check with `PermissionManager::check(..., scope: $orgScope)`.

No schema change, no API change — the scope column and the resolver already
understand organization (and team/project) scopes today. Scope isolation is
enforced (a global assignment never leaks into a scoped check, and vice versa)
and covered by tests.

## Extending via the hook pipeline

Every check runs the core `before:permission.check` hook, so other plugins can
observe or veto:

```php
// In any plugin implementing HasHooks:
public function beforeHooks(): array
{
    return [
        'permission.check' => function ($ctx) {
            if (isSuspended($ctx->get('subject_id'))) {
                $ctx->set('allow', false)->stop(); // hard deny
            }
        },
    ];
}
```

## Developing against a local core

```bash
composer config repositories.auth-kit path ../auth-kit
composer require gusmanwidodo/auth-kit:@dev
composer install
composer test    # 13 tests: correctness + query-count assertions
composer bench   # benchmark vs spatie
```

## License

MIT © Gusman Widodo. See [LICENSE](LICENSE).
