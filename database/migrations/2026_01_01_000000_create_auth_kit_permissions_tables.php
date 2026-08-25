<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Permissions catalog: resource + action pairs (e.g. post.create).
        Schema::create('auth_kit_permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('resource');
            $table->string('action');
            $table->timestamps();

            $table->unique(['resource', 'action']);
        });

        // Dynamic roles. Scope is polymorphic and nullable: null = global,
        // otherwise (scope_type, scope_id) e.g. ('organization', 42).
        Schema::create('auth_kit_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('scope_type')->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->timestamps();

            // A role name is unique within a scope (or globally when null).
            $table->unique(['name', 'scope_type', 'scope_id']);
            $table->index(['scope_type', 'scope_id']);
        });

        // Role <-> permission pivot.
        Schema::create('auth_kit_role_permission', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained('auth_kit_roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('auth_kit_permissions')->cascadeOnDelete();

            $table->primary(['role_id', 'permission_id']);
        });

        // Assignment of a role to a subject (usually a user), within a scope.
        // subject is polymorphic so any model can hold roles.
        Schema::create('auth_kit_role_assignments', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('role_id')->constrained('auth_kit_roles')->cascadeOnDelete();
            $table->string('scope_type')->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id', 'role_id', 'scope_type', 'scope_id'], 'akra_unique');
            // The hot lookup path: "roles for this subject in this scope".
            $table->index(['subject_type', 'subject_id', 'scope_type', 'scope_id'], 'akra_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_kit_role_assignments');
        Schema::dropIfExists('auth_kit_role_permission');
        Schema::dropIfExists('auth_kit_roles');
        Schema::dropIfExists('auth_kit_permissions');
    }
};
