<?php

declare(strict_types=1);

namespace Gusmanwidodo\AuthKitPermissions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A dynamic (database-defined) role, optionally scoped via a polymorphic
 * (scope_type, scope_id) pair. null scope = global.
 *
 * @property int $id
 * @property string $name
 * @property string|null $scope_type
 * @property int|null $scope_id
 */
class Role extends Model
{
    protected $table = 'auth_kit_roles';

    protected $fillable = ['name', 'scope_type', 'scope_id'];

    protected $casts = [
        'scope_id' => 'integer',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'auth_kit_role_permission',
            'role_id',
            'permission_id',
        );
    }
}
