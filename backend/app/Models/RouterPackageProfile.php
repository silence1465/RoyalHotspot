<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouterPackageProfile extends Model
{
    protected $fillable = ['router_id', 'package_id', 'profile_name', 'shared_users'];

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function package()
    {
        return $this->belongsTo(InternetPackage::class, 'package_id');
    }
}
