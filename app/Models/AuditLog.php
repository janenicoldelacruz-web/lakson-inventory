<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AuditLog extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'changes',
        'meta',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'changes' => 'array',
        'meta'    => 'array',
    ];
}
