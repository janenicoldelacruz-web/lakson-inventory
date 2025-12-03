<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoginHistory extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'ip_address', 'device', 'login_at'];

    // Optionally, you can define relationships (e.g., the User relation)
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
