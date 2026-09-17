<?php

namespace App\Support;

class AdminPermissions
{
    public const ALL = [
        'dashboard.view',
        'customers.view',
        'customers.manage',
        'transactions.paystack.view',
        'transactions.momo.view',
        'bandwidth.view',
        'sessions.view',
        'reports.view',
        'packages.view',
        'packages.manage',
        'vouchers.view',
        'vouchers.manage',
        'purchases.assign',
        'routers.view',
        'routers.manage',
        'complaints.view',
        'complaints.manage',
        'logs.view',
    ];
}
