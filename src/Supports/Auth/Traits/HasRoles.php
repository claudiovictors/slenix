<?php

/*
|--------------------------------------------------------------------------
| HasRoles
|--------------------------------------------------------------------------
|
| Trait that adds role and permission management to a user model.
| Roles are stored in the 'roles' table and linked to users via the
| 'role_user' pivot table. Permissions are linked to roles via the
| 'permission_role' pivot table.
|
| Add this trait to your User model alongside Authenticatable:
|
|   use Authenticatable, HasRoles;
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Auth\Traits;

use Slenix\Database\Model;
use Slenix\Database\Relations\BelongsToMany;

/**
 * @mixin Model
 */
trait HasRoles
{
    /**
     * The roles that belong to the user.
     *
     * Many-to-many via the 'role_user' pivot table.
     *
     * @return BelongsToMany
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\Models\Role::class,
            'role_user',
            'user_id',
            'role_id'
        );
    }

    /**
     * Check if the user has a specific role by name.
     *
     * @example auth()->user()->hasRole('admin')
     *
     * @param  string $role  The role name to check.
     * @return bool
     */
    public function hasRole(string $role): bool
    {
        foreach ($this->roles as $r) {
            if ($r->name === $role) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the user has at least one of the given roles.
     *
     * @example auth()->user()->hasAnyRole(['admin', 'editor'])
     *
     * @param  string[] $roles  List of role names to check.
     * @return bool
     */
    public function hasAnyRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the user has a specific permission via any of their roles.
     *
     * Permissions are not assigned directly to users — they are assigned
     * to roles, and users inherit them through role membership.
     *
     * @example auth()->user()->can('edit-posts')
     *
     * @param  string $permission  The permission name to check.
     * @return bool
     */
    public function can(string $permission): bool
    {
        foreach ($this->roles as $role) {
            foreach ($role->permissions as $perm) {
                if ($perm->name === $permission) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Assign a role to the user by name.
     *
     * Creates the role if it does not already exist.
     *
     * @param  string $roleName  The role name to assign.
     * @return static
     */
    public function assignRole(string $roleName): static
    {
        $role = \App\Models\Role::firstOrCreate(['name' => $roleName]);
        $this->roles()->attach($role->id);
        return $this;
    }

    /**
     * Remove a role from the user by name.
     *
     * Does nothing if the user does not have the role.
     *
     * @param  string $roleName  The role name to remove.
     * @return static
     */
    public function removeRole(string $roleName): static
    {
        $role = \App\Models\Role::firstWhere('name', $roleName);

        if ($role) {
            $this->roles()->detach($role->id);
        }

        return $this;
    }

    /**
     * Sync the user's roles, replacing all existing roles with the given list.
     *
     * Roles that exist in the database but are not in $roleNames will be
     * detached. Roles not yet in the database will be created automatically.
     *
     * @param  string[] $roleNames  The complete list of role names to assign.
     * @return static
     */
    public function syncRoles(array $roleNames): static
    {
        $ids = array_map(
            fn($name) => \App\Models\Role::firstOrCreate(['name' => $name])->id,
            $roleNames
        );

        $this->roles()->sync($ids);
        return $this;
    }
}