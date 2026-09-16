<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AdminRouterScope
{
    public static function ids(Request $request): ?array
    {
        return $request->attributes->get('admin_router_ids');
    }

    public static function apply(Builder $query, Request $request, string $column = 'router_id'): Builder
    {
        $ids = static::ids($request);

        return $ids === null ? $query : $query->whereIn($column, $ids);
    }
}
