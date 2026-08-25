<?php

declare(strict_types=1);

use Gusmanwidodo\AuthKitPermissions\Http\PermissionController;
use Illuminate\Support\Facades\Route;

// Mounted under "{prefix}/permissions" by the Auth-Kit core
// (default: /auth-kit/permissions).
Route::post('/check', [PermissionController::class, 'check'])->name('auth-kit.permissions.check');
