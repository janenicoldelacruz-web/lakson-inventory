<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'password',
        'role',   // 'owner' or 'customer'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];
}
