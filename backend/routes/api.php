<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ── Public / guest ──────────────────────────────────────────
    Route::post('/admin/login', [\App\Http\Controllers\Auth\AdminAuthController::class, 'login'])
        ->middleware('throttle:login');
    Route::post('/customer/register', [\App\Http\Controllers\Auth\CustomerAuthController::class, 'register'])
        ->middleware('throttle:login');
    Route::post('/customer/login', [\App\Http\Controllers\Auth\CustomerAuthController::class, 'login'])
        ->middleware('throttle:login');
    Route::post('/password/forgot', [\App\Http\Controllers\Auth\PasswordRecoveryController::class, 'forgot'])
        ->middleware('throttle:login');
    Route::post('/password/reset', [\App\Http\Controllers\Auth\PasswordRecoveryController::class, 'reset'])
        ->middleware('throttle:login');
    Route::get('/customer/packages', [\App\Http\Controllers\Customer\PackageController::class, 'index']);

    // ── Guest checkout — no account required ────────────────────
    // Every route here already sits under the global 60/min 'api'
    // throttle (see AppServiceProvider). Extra throttling only added
    // where the risk profile actually differs: purchase creation and
    // phone-number lookup are the two an anonymous visitor could abuse
    // (spam purchases, enumerate phone numbers) — status polling
    // deliberately gets NO extra throttle since the payment page polls
    // it every few seconds, same as the customer equivalent.
    // Guest checkout is intentionally disabled. Phone-number-only recovery
    // exposed active hotspot credentials and is not an ownership factor.

    // Public — RouterOS /tool fetch downloads this directly, no auth
    // (a router can't present a Sanctum token). See
    // MikrotikLoginPageController for why this is safe to leave open.
    Route::get('/mikrotik/login/{router}', [\App\Http\Controllers\MikrotikLoginPageController::class, 'show']);

    // Paystack webhook — HMAC-verified, not Sanctum-authenticated.
    Route::post('/webhooks/paystack', [\App\Http\Controllers\WebhookController::class, 'paystack']);

    // SMS Forwarder webhook — shared-secret token auth.
    Route::post('/webhooks/sms-payment', [\App\Http\Controllers\PaymentSmsWebhookController::class, 'receive'])
        ->middleware(['sms-forwarder', 'throttle:sms-webhook']);
    Route::match(['get', 'post'], '/webhooks/sms-heartbeat', [\App\Http\Controllers\PaymentSmsWebhookController::class, 'heartbeat'])
        ->middleware(['sms-forwarder', 'throttle:sms-webhook'])
        ->name('sms-forwarder.heartbeat');

    // Public — checked from both the customer and guest payment pages
    // when submitting a transaction ID, so the person isn't left
    // guessing why nothing's happening if the relay phone is down.
    Route::get('/sms-forwarder/status', [\App\Http\Controllers\SmsForwarderStatusController::class, 'show'])
        ->middleware('throttle:api');

    // ── Admin ───────────────────────────────────────────────────
    Route::middleware(['auth:admin', 'abilities:admin'])->prefix('admin')->group(function () {
        Route::post('/logout', [\App\Http\Controllers\Auth\AdminAuthController::class, 'logout']);
        Route::get('/me', [\App\Http\Controllers\Auth\AdminAuthController::class, 'me']);
        Route::post('/mfa/setup', [\App\Http\Controllers\Auth\AdminTwoFactorController::class, 'setup'])->middleware('role:super_admin,admin');
        Route::post('/mfa/confirm', [\App\Http\Controllers\Auth\AdminTwoFactorController::class, 'confirm'])->middleware('role:super_admin,admin');
        Route::post('/mfa/disable', [\App\Http\Controllers\Auth\AdminTwoFactorController::class, 'disable'])->middleware('role:super_admin,admin');

        Route::get('/mikrotik-security/status', [\App\Http\Controllers\Admin\MikrotikSecurityController::class, 'status'])->middleware('role:super_admin,admin');
        Route::post('/mikrotik-security/unlock', [\App\Http\Controllers\Admin\MikrotikSecurityController::class, 'unlock'])->middleware(['role:super_admin,admin', 'throttle:mikrotik-unlock']);
        Route::post('/mikrotik-security/lock', [\App\Http\Controllers\Admin\MikrotikSecurityController::class, 'lock'])->middleware('role:super_admin,admin');

        Route::get('/dashboard/stats', [\App\Http\Controllers\Admin\DashboardController::class, 'stats']);
        Route::get('/dashboard/chart-data', [\App\Http\Controllers\Admin\DashboardController::class, 'chartData']);
        Route::get('/notifications/summary', [\App\Http\Controllers\Admin\NotificationController::class, 'summary']);
        Route::get('/active-users', [\App\Http\Controllers\Admin\ActiveUsersController::class, 'index'])->middleware('mikrotik-unlocked');
        Route::post('/active-users/reset-session', [\App\Http\Controllers\Admin\ActiveUsersController::class, 'resetSession'])
            ->middleware(['role:super_admin,admin', 'mikrotik-unlocked']);
        Route::get('/bandwidth/summary', [\App\Http\Controllers\Admin\BandwidthController::class, 'summary'])->middleware('mikrotik-unlocked');
        Route::get('/bandwidth/history', [\App\Http\Controllers\Admin\BandwidthController::class, 'history'])->middleware('mikrotik-unlocked');
        Route::post('/bandwidth/capacity', [\App\Http\Controllers\Admin\BandwidthController::class, 'updateCapacity'])
            ->middleware(['role:super_admin,admin', 'mikrotik-unlocked']);
        Route::get('/search', [\App\Http\Controllers\Admin\SearchController::class, 'index']);

        Route::apiResource('routers', \App\Http\Controllers\Admin\RouterController::class)
            ->middleware(['role:super_admin,admin', 'mikrotik-unlocked']);
        Route::post('/routers/{router}/test-connection', [\App\Http\Controllers\Admin\RouterController::class, 'testConnection'])
            ->middleware(['role:super_admin,admin', 'mikrotik-unlocked']);
        Route::post('/routers/{router}/setup-guest-portal', [\App\Http\Controllers\Admin\RouterController::class, 'setupGuestPortal'])
            ->middleware(['role:super_admin,admin', 'mikrotik-unlocked']);

        Route::middleware(['role:super_admin,admin', 'mikrotik-unlocked'])->prefix('router-management/{router}')->group(function () {
            Route::get('/overview', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'overview']);
            Route::get('/users', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'users']);
            Route::post('/users', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'storeUser']);
            Route::patch('/users/{userId}', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'updateUser']);
            Route::delete('/users/{userId}', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'destroyUser']);
            Route::get('/hosts', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'hosts']);
            Route::get('/bindings', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'bindings']);
            Route::post('/bindings', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'storeBinding']);
            Route::patch('/bindings/{bindingId}/status', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'setBindingStatus']);
            Route::patch('/bindings/{bindingId}', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'updateBinding']);
            Route::delete('/bindings/{bindingId}', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'destroyBinding']);
            Route::get('/profiles', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'profiles']);
            Route::post('/profiles', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'storeProfile']);
            Route::patch('/profiles/{profileId}', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'updateProfile']);
            Route::delete('/profiles/{profileId}', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'destroyProfile']);
            Route::get('/dhcp-servers', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'dhcpServers']);
            Route::get('/dhcp-leases', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'dhcpLeases']);
            Route::post('/dhcp-leases/{leaseId}/make-static', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'makeLeaseStatic']);
            Route::delete('/dhcp-leases/{leaseId}', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'destroyLease']);
            Route::patch('/dhcp-leases/{leaseId}', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'updateLease']);
            Route::get('/queues', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'queues']);
            Route::post('/queues', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'storeQueue']);
            Route::patch('/queues/{queueId}', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'updateQueue']);
            Route::delete('/queues/{queueId}', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'destroyQueue']);
            Route::get('/router-logs', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'routerLogs']);
            Route::get('/address-lists', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'addressLists']);
            Route::post('/address-lists', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'storeAddressList']);
            Route::patch('/address-lists/{entryId}', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'updateAddressList']);
            Route::delete('/address-lists/{entryId}', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'destroyAddressList']);
            Route::get('/backups', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'backups']);
            Route::post('/backups', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'storeBackup']);
            Route::delete('/backups/{fileId}', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'destroyBackup']);
            Route::get('/system-information', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'systemInformation']);
            Route::post('/diagnostics', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'diagnostic']);
            Route::post('/terminal', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'terminal']);
            Route::get('/modules/{module}', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'expandedModule']);
            Route::post('/modules/{module}', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'storeExpanded']);
            Route::patch('/modules/{module}/{itemId}', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'updateExpanded']);
            Route::delete('/modules/{module}/{itemId}', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'destroyExpanded']);
            Route::get('/bridges', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'bridges']);
            Route::post('/bridges', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'storeBridge']);
            Route::patch('/bridges/{bridgeId}', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'updateBridge']);
            Route::delete('/bridges/{bridgeId}', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'destroyBridge']);
            Route::get('/bridge-ports', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'bridgePorts']);
            Route::post('/bridge-ports', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'storeBridgePort']);
            Route::patch('/bridge-ports/{portId}', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'updateBridgePort']);
            Route::delete('/bridge-ports/{portId}', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'destroyBridgePort']);
            Route::get('/bridge-hosts', [\App\Http\Controllers\Admin\MikrotikManagementController::class, 'bridgeHosts']);
        });

        Route::apiResource('packages', \App\Http\Controllers\Admin\PackageController::class)
            ->only(['index', 'show'])->middleware('role:super_admin,admin');
        Route::apiResource('packages', \App\Http\Controllers\Admin\PackageController::class)
            ->only(['store', 'update', 'destroy'])->middleware(['role:super_admin,admin', 'mikrotik-unlocked']);
        Route::apiResource('free-trials', \App\Http\Controllers\Admin\FreeTrialCampaignController::class)
            ->parameters(['free-trials' => 'campaign'])->except('show')
            ->middleware(['role:super_admin,admin', 'mikrotik-unlocked']);

        Route::get('/customers', [\App\Http\Controllers\Admin\CustomerController::class, 'index']);
        Route::get('/customers/{customer}', [\App\Http\Controllers\Admin\CustomerController::class, 'show']);

        // Unified purchases — replaces both /subscriptions and /orders
        Route::get('/purchases', [\App\Http\Controllers\Admin\PurchaseController::class, 'index']);
        Route::get('/purchases/{purchase}', [\App\Http\Controllers\Admin\PurchaseController::class, 'show']);
        Route::middleware('role:super_admin,admin')->group(function () {
            Route::post('/purchases/{purchase}/approve', [\App\Http\Controllers\Admin\PurchaseController::class, 'approve']);
            Route::post('/purchases/{purchase}/reject', [\App\Http\Controllers\Admin\PurchaseController::class, 'reject']);
            Route::post('/purchases/{purchase}/cancel', [\App\Http\Controllers\Admin\PurchaseController::class, 'cancel']);
            Route::post('/purchases/{purchase}/suspend', [\App\Http\Controllers\Admin\PurchaseController::class, 'suspend']);
            Route::post('/purchases/{purchase}/activate', [\App\Http\Controllers\Admin\PurchaseController::class, 'activate']);
            Route::post('/purchases/{purchase}/retry-activation', [\App\Http\Controllers\Admin\PurchaseController::class, 'retryActivation']);
            Route::post('/purchases/{purchase}/assign-voucher', [\App\Http\Controllers\Admin\PurchaseController::class, 'assignVoucher']);
            Route::post('/purchases/assign', [\App\Http\Controllers\Admin\PurchaseController::class, 'assign']);
        });

        Route::get('/vouchers', [\App\Http\Controllers\Admin\VoucherController::class, 'index'])->middleware('role:super_admin,admin');
        Route::get('/vouchers/inventory', [\App\Http\Controllers\Admin\VoucherController::class, 'inventory'])->middleware('role:super_admin,admin');
        Route::post('/vouchers/generate', [\App\Http\Controllers\Admin\VoucherController::class, 'generate'])->middleware('role:super_admin,admin');
        Route::delete('/vouchers/{voucher}', [\App\Http\Controllers\Admin\VoucherController::class, 'destroy'])->middleware('role:super_admin,admin');
        Route::post('/vouchers/{voucher}/check-mikrotik-status', [\App\Http\Controllers\Admin\VoucherController::class, 'checkMikrotikStatus'])->middleware(['role:super_admin,admin', 'mikrotik-unlocked']);

        Route::middleware('role:super_admin,admin')->group(function () {
            Route::get('/vouchers/import', [\App\Http\Controllers\Admin\VoucherImportController::class, 'index']);
            Route::post('/vouchers/import/upload', [\App\Http\Controllers\Admin\VoucherImportController::class, 'upload']);
            Route::get('/vouchers/import/{batch}', [\App\Http\Controllers\Admin\VoucherImportController::class, 'show']);
            Route::post('/vouchers/import/{batch}/confirm', [\App\Http\Controllers\Admin\VoucherImportController::class, 'confirm']);
            Route::post('/vouchers/import/{batch}/cancel', [\App\Http\Controllers\Admin\VoucherImportController::class, 'cancel']);
        });

        Route::get('/mikrotik-logs', [\App\Http\Controllers\Admin\LogController::class, 'mikrotikLogs'])->middleware('mikrotik-unlocked');
        Route::get('/activity-logs', [\App\Http\Controllers\Admin\LogController::class, 'activityLogs']);
        Route::get('/sms-logs', [\App\Http\Controllers\Admin\LogController::class, 'smsLogs']);

        Route::get('/reports/revenue', [\App\Http\Controllers\Admin\ReportController::class, 'revenue']);
        Route::get('/reports/accounting', [\App\Http\Controllers\Admin\ReportController::class, 'accounting'])
            ->middleware('role:super_admin,admin');
        Route::get('/reports/payments', [\App\Http\Controllers\Admin\ReportController::class, 'payments']);
        Route::get('/reports/customers', [\App\Http\Controllers\Admin\ReportController::class, 'customers']);
        Route::get('/reports/router-activity', [\App\Http\Controllers\Admin\ReportController::class, 'routerActivity'])->middleware('mikrotik-unlocked');

        Route::get('/settings', [\App\Http\Controllers\Admin\SettingController::class, 'index'])->middleware('role:super_admin,admin');
        Route::put('/settings', [\App\Http\Controllers\Admin\SettingController::class, 'update'])->middleware('role:super_admin,admin');

        Route::get('/complaints', [\App\Http\Controllers\Admin\ComplaintController::class, 'index']);
        Route::get('/complaints/{complaint}', [\App\Http\Controllers\Admin\ComplaintController::class, 'show']);
        Route::post('/complaints/{complaint}/respond', [\App\Http\Controllers\Admin\ComplaintController::class, 'respond']);
    });

    // ── Customer ────────────────────────────────────────────────
    Route::middleware(['auth:customer', 'abilities:customer'])->prefix('customer')->group(function () {
        Route::post('/logout', [\App\Http\Controllers\Auth\CustomerAuthController::class, 'logout']);
        Route::get('/me', [\App\Http\Controllers\Auth\CustomerAuthController::class, 'me']);
        Route::get('/free-trial', [\App\Http\Controllers\Customer\FreeTrialController::class, 'offer']);
        Route::post('/free-trial/claim', [\App\Http\Controllers\Customer\FreeTrialController::class, 'claim'])
            ->middleware('throttle:login');

        Route::get('/dashboard', [\App\Http\Controllers\Customer\DashboardController::class, 'index']);
        Route::put('/profile', [\App\Http\Controllers\Customer\ProfileController::class, 'update']);
        Route::post('/hotspot/sessions/prepare', [\App\Http\Controllers\Customer\HotspotSessionController::class, 'prepare'])
            ->middleware('throttle:hotspot-connect');
        Route::get('/hotspot/sessions/{session}', [\App\Http\Controllers\Customer\HotspotSessionController::class, 'status'])
            ->middleware('throttle:hotspot-status');

        // Unified purchases — replaces PaymentController + OrderController
        Route::get('/purchases', [\App\Http\Controllers\Customer\PurchaseController::class, 'index']);
        Route::post('/purchases', [\App\Http\Controllers\Customer\PurchaseController::class, 'store']);
        Route::get('/purchases/{reference}', [\App\Http\Controllers\Customer\PurchaseController::class, 'show']);
        Route::get('/purchases/{reference}/status', [\App\Http\Controllers\Customer\PurchaseController::class, 'status']);
        Route::post('/purchases/{reference}/acknowledge-payment', [\App\Http\Controllers\Customer\PurchaseController::class, 'acknowledgePayment']);
        Route::post('/purchases/{reference}/verify', [\App\Http\Controllers\Customer\PurchaseController::class, 'verify'])
            ->middleware('throttle:order-verify');
        Route::post('/purchases/{purchase}/activate', [\App\Http\Controllers\Customer\PurchaseController::class, 'activate']);
        Route::post('/purchases/{purchase}/connect', [\App\Http\Controllers\Customer\PurchaseController::class, 'connect']);

        Route::post('/vouchers/redeem', [\App\Http\Controllers\Customer\VoucherController::class, 'redeem'])
            ->middleware('throttle:voucher-redeem');
        Route::get('/my-vouchers', [\App\Http\Controllers\Customer\VoucherController::class, 'myVouchers']);

        Route::get('/complaints', [\App\Http\Controllers\Customer\ComplaintController::class, 'index']);
        Route::post('/complaints', [\App\Http\Controllers\Customer\ComplaintController::class, 'store']);
    });
});
