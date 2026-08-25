<?php

declare(strict_types=1);

namespace Gusmanwidodo\AuthKitPermissions\Tests;

use Gusmanwidodo\AuthKit\AuthKitServiceProvider;
use Gusmanwidodo\AuthKitPermissions\PermissionsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            AuthKitServiceProvider::class,
            PermissionsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // A representative static statement + roles for the tests.
        $app['config']->set('auth-kit-permissions.statement', [
            'post' => ['create', 'read', 'update', 'delete'],
            'member' => ['create', 'update', 'delete'],
        ]);
        $app['config']->set('auth-kit-permissions.roles', [
            'admin' => ['post' => '*', 'member' => '*'],
            'author' => ['post' => ['create', 'read', 'update']],
            'viewer' => ['post' => ['read']],
        ]);
    }
}
