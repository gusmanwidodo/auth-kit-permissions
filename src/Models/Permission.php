<?php

declare(strict_types=1);

namespace Gusmanwidodo\AuthKitPermissions\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A permission = a (resource, action) pair, e.g. resource="post" action="create".
 *
 * @property int $id
 * @property string $resource
 * @property string $action
 */
class Permission extends Model
{
    protected $table = 'auth_kit_permissions';

    protected $fillable = ['resource', 'action'];

    /** The canonical "resource.action" ability string. */
    public function ability(): string
    {
        return $this->resource . '.' . $this->action;
    }
}
