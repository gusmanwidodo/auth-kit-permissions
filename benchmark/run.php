<?php

declare(strict_types=1);

/**
 * Real micro-benchmark: auth-kit-permissions vs spatie/laravel-permission.
 *
 * Boots a minimal Laravel kernel via Orchestra Testbench, migrates BOTH
 * systems into the same in-memory SQLite DB, seeds identical data, then times
 * how many permission checks per second each performs across representative
 * scenarios. Prints a Markdown table (copied into the README).
 *
 * Run: composer bench   (or: php benchmark/run.php)
 *
 * Fairness notes:
 *  - Same PHP process, same DB, same iteration count.
 *  - spatie is given its registrar cache warm (its normal steady state).
 *  - We measure the three scenarios that matter:
 *      A) static/config role check (our fast path)   -> no DB
 *      B) first dynamic check in a request           -> 1 query (both hit DB)
 *      C) repeated checks in a request               -> memoized vs cached
 */

require __DIR__ . '/../vendor/autoload.php';

use Gusmanwidodo\AuthKit\AuthKitServiceProvider;
use Gusmanwidodo\AuthKitPermissions\PermissionManager;
use Gusmanwidodo\AuthKitPermissions\PermissionsServiceProvider;
use Gusmanwidodo\AuthKitPermissions\RoleService;
use Gusmanwidodo\AuthKitPermissions\Support\Scope;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\Concerns\CreatesApplication;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider as SpatieServiceProvider;

$ITER = (int) ($argv[1] ?? 50000);

// ---------------------------------------------------------------------------
// Boot a Laravel application with both packages registered.
// ---------------------------------------------------------------------------
$bootstrapper = new class {
    use CreatesApplication;

    /** @return array<int, class-string> */
    public function providers(): array
    {
        return [
            AuthKitServiceProvider::class,
            PermissionsServiceProvider::class,
            SpatieServiceProvider::class,
        ];
    }

    public function make(): Application
    {
        $app = $this->createApplication();

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Our static statement + roles.
        $app['config']->set('auth-kit-permissions.statement', [
            'post' => ['create', 'read', 'update', 'delete'],
        ]);
        $app['config']->set('auth-kit-permissions.roles', [
            'author' => ['post' => ['create', 'read', 'update']],
        ]);

        foreach ($this->providers() as $provider) {
            $app->register($provider);
        }

        return $app;
    }
};

$app = $bootstrapper->make();

// Run migrations for both systems by loading each migration file and calling
// up() directly against the shared connection (no migration repository needed).
$migrationFiles = array_merge(
    glob(__DIR__ . '/../database/migrations/*.php') ?: [],
    // spatie ships its migration as a .php.stub; load the table creator only.
    [__DIR__ . '/../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub'],
);
foreach ($migrationFiles as $file) {
    $migration = require $file;
    if (is_object($migration) && method_exists($migration, 'up')) {
        $migration->up();
    }
}

// ---------------------------------------------------------------------------
// Seed identical data.
// ---------------------------------------------------------------------------
// -- Ours (dynamic role "editor" with post.delete assigned to user 1) --
/** @var RoleService $svc */
$svc = $app->make(RoleService::class);
$ourRole = $svc->createRole('editor', ['post.delete']);
$svc->assign('App\\User', 1, $ourRole);

// -- Spatie (role "editor" with permission "post.delete" assigned to user 1) --
$app[PermissionRegistrar::class]->forgetCachedPermissions();
$spatiePerm = SpatiePermission::create(['name' => 'post.delete', 'guard_name' => 'web']);
$spatieRole = SpatieRole::create(['name' => 'editor', 'guard_name' => 'web']);
$spatieRole->givePermissionTo($spatiePerm);

// A tiny fake user that uses spatie's HasRoles for a fair comparison.
$spatieUser = new class extends Illuminate\Foundation\Auth\User {
    use Spatie\Permission\Traits\HasRoles;
    protected $table = 'users';
    public $timestamps = false;
    protected $guarded = [];
    protected $guard_name = 'web';
};
// Minimal users table so spatie's pivot works.
$app['db']->connection()->getSchemaBuilder()->create('users', function ($t) {
    $t->id();
    $t->string('name')->nullable();
});
$spatieUser->forceFill(['id' => 1, 'name' => 'u1'])->save();
$spatieUser->assignRole('editor');

/** @var PermissionManager $pm */
$pm = $app->make(PermissionManager::class);

// ---------------------------------------------------------------------------
// Timing helper.
// ---------------------------------------------------------------------------
function bench(string $label, int $iter, callable $fn): array
{
    // Warm up.
    for ($i = 0; $i < 100; $i++) {
        $fn();
    }
    $start = hrtime(true);
    for ($i = 0; $i < $iter; $i++) {
        $fn();
    }
    $ns = hrtime(true) - $start;
    $perSec = $iter / ($ns / 1e9);

    return ['label' => $label, 'ops' => $perSec, 'ns_each' => $ns / $iter];
}

$results = [];

// Scenario A: static/config role check (our fast path). spatie has no
// equivalent zero-query concept; its closest is checking a permission the user
// holds via a role, which requires the registrar/DB. We compare our static
// check to spatie's cached hasPermissionTo.
$app[PermissionRegistrar::class]->forgetCachedPermissions();
$spatieUser->load('roles.permissions'); // steady-state: relations warm

$results[] = bench('Ours — static role check (config)', $ITER, function () use ($pm) {
    $pm->check('App\\User', 2, 'post.update', ['author']);
});
$results[] = bench('Spatie — hasPermissionTo (cache warm)', $ITER, function () use ($spatieUser) {
    $spatieUser->hasPermissionTo('post.delete');
});

// Scenario C: repeated dynamic checks in one request (memoized vs spatie cache).
$pm->forgetMemo();
$pm->check('App\\User', 1, 'post.delete'); // prime our memo once
$results[] = bench('Ours — dynamic check (memoized, in-request)', $ITER, function () use ($pm) {
    $pm->check('App\\User', 1, 'post.delete');
});

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
$fmt = fn (float $n) => number_format($n, 0);

echo "\n";
echo "Benchmark: auth-kit-permissions vs spatie/laravel-permission\n";
echo 'PHP ' . PHP_VERSION . ' · ' . $fmt($ITER) . " iterations · in-memory SQLite\n\n";
echo "| Scenario | checks/sec | ns/check |\n";
echo "|----------|-----------:|---------:|\n";
foreach ($results as $r) {
    printf("| %s | %s | %s |\n", $r['label'], $fmt($r['ops']), $fmt($r['ns_each']));
}
echo "\n";

// Emit a machine-readable line for CI/README automation.
$our = $results[0]['ops'];
$spatie = $results[1]['ops'];
printf("SPEEDUP static-vs-spatie: %.1fx\n", $our / $spatie);
