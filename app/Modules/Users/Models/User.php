<?php

namespace App\Modules\Users\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/**
 * Eloquent model only for Sanctum token issuing (tokenable). Game code reads users through
 * UsersRepository, never through this model.
 */
class User extends Model
{
    use HasApiTokens, HasUuids;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}
