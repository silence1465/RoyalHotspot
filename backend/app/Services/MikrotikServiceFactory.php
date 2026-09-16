<?php

namespace App\Services;

use App\Models\Router;

class MikrotikServiceFactory
{
    public function make(Router $router): MikrotikService
    {
        return new MikrotikService($router);
    }
}
