<?php

namespace App\Models;

use Slenix\Database\Model;

class User extends Model {
    protected string $table = 'users';
    protected array $fillable = ['name', 'email', 'password'];

}