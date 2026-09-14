<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MikrotikAdminUnlock extends Model
{
    protected $fillable = ['user_id', 'personal_access_token_id', 'unlocked_at', 'last_used_at', 'expires_at', 'ip_address', 'user_agent'];

    protected $casts = [
        'unlocked_at' => 'datetime',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
