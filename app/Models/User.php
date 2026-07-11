<?php

declare(strict_types=1);

namespace App\Models;

use Slenix\Database\Model;
use Slenix\Supports\Auth\Traits\Authenticatable;
use Slenix\Supports\Auth\Traits\HasRoles;

/**
 * User Model
 *
 * Represents an application user. Extends Slenix's base Model
 * and uses the Authenticatable trait to integrate with the auth
 * system, and HasRoles for role-based access control.
 */
class User extends Model
{
    use Authenticatable, HasRoles;

    /**
     * The database table associated with this model.
     */
    protected string $table = 'users';

    /**
     * The primary key column name.
     */
    protected string $primaryKey = 'id';

    /**
     * Attributes that are mass-assignable.
     *
     * @var array<string>
     */
    protected array $fillable = [
        'name',
        'email',
        'email_verified_at',
        'password',
    ];

    /**
     * Attributes excluded from serialization (e.g. JSON responses).
     *
     * @var array<string>
     */
    protected array $hidden = [
        'password',
    ];

    /**
     * Attribute type casts applied when reading from the database.
     *
     * @var array<string, string>
     */
    protected array $casts = [
        'id'                => 'integer',
        'email_verified_at' => 'datetime',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
    ];
}