<?php

use App\Http\Controllers\Admin\ActiveUsersController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\BandwidthController;
use App\Http\Controllers\Admin\ComplaintController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FreeTrialCampaignController;
use App\Http\Controllers\Admin\IspSessionController;
use App\Http\Controllers\Admin\LogController;
use App\Http\Controllers\Admin\MikrotikManagementController;
use App\Http\Controllers\Admin\MikrotikSecurityController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\PurchaseController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RouterController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\VoucherController;
use App\Http\Controllers\Admin\VoucherImportController;
use App\Http\Controllers\Auth\AdminAuthController;
use App\Http\Controllers\Auth\AdminTwoFactorController;
use App\Http\Controllers\Auth\CustomerAuthController;
use App\Http\Controllers\Auth\PasswordRecoveryController;
use App\Http\Controllers\Customer\FreeTrialController;
use App\Http\Controllers\Customer\HotspotSessionController;
use App\Http\Controllers\Customer\PackageController;
use App\Http\Controllers\Customer\ProfileController;
use App\Http\Controllers\MikrotikLoginPageController;
use App\Http\Controllers\PaymentSmsWebhookController;
use App\Http\Controllers\SmsForwarderStatusController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ── Public / guest ──────────────────────────────────────────
    Route::post('/admin/login', [AdminAuthController::class, 'login'])
        ->middleware('throttle:login');
    Route::post('/customer/register', [CustomerAuthController::class, 'register'])
        ->middleware('throttle:login');
    Route::post('/customer/login', [CustomerAuthController::class, 'login'])
        ->middleware('throttle:login');
    Route::post('/password/forgot', [PasswordRecoveryController::class, 'forgot'])
        ->middleware('throttle:login');
    Route::post('/password/reset', [PasswordRecoveryController::class, 'reset'])
        ->middleware('throttle:login');
    Route::get('/customer/packages', [PackageController::class, 'index']);

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
    Route::get('/mikrotik/login/{router}', [MikrotikLoginPageController::class, 'show']);

    // Paystack webhook — HMAC-verified, not Sanctum-authenticated.
    Route::post('/webhooks/paystack', [WebhookController::class, 'paystack']);

    // SMS Forwarder webhook — shared-secret token auth.
    Route::post('/webhooks/sms-payment', [PaymentSmsWebhookController::class, 'receive'])
        ->middleware(['sms-forwarder', 'throttle:sms-webhook']);
    Route::match(['get', 'post'], '/webhooks/sms-heartbeat', [PaymentSmsWebhookController::class, 'heartbeat'])
        ->middleware(['sms-forwarder', 'throttle:sms-webhook'])
        ->name('sms-forwarder.heartbeat');

    // Public — checked from both the customer and guest payment pages
    // when submitting a transaction ID, so the person isn't left
    // guessing why nothing's happening if the relay phone is down.
    Route::get('/sms-forwarder/status', [SmsForwarderStatusController::class, 'show'])
        ->middleware('throttle:api');

    // ── Admin ───────────────────────────────────────────────────
    Route::middleware(['auth:admin', 'abilities:admin', 'admin-scope'])->prefix('admin')->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout']);
        Route::get('/me', [AdminAuthController::class, 'me']);
        Route::get('/admin-users', [AdminUserController::class, 'index'])
            ->middleware('role:super_admin');
        Route::post('/admin-users', [AdminUserController::class, 'store'])
            ->middleware('role:super_admin');
        Route::put('/admin-users/{admin}', [AdminUserController::class, 'update'])
            ->middleware('role:super_admin');
        Route::post('/mfa/setup', [AdminTwoFactorController::class, 'setup'])->middleware('role:super_admin,admin');
        Route::post('/mfa/confirm', [AdminTwoFactorController::class, 'confirm'])->middleware('role:super_admin,admin');
        Route::post('/mfa/disable', [AdminTwoFactorController::class, 'disable'])->middleware('role:super_admin,admin');

        Route::get('/mikrotik-security/status', [MikrotikSecurityController::class, 'status'])->middleware('role:super_admin,admin');
        Route::post('/mikrotik-security/unlock', [MikrotikSecurityController::class, 'unlock'])->middleware(['role:super_admin,admin', 'throttle:mikrotik-unlock']);
        Route::post('/mikrotik-security/lock', [MikrotikSecurityController::class, 'lock'])->middleware('role:super_admin,admin');

        Route::get('/dashboard/stats', [DashboardController::class, 'stats'])->middleware('admin-permission:dashboard.view');
        Route::get('/dashboard/chart-data', [DashboardController::class, 'chartData'])->middleware('admin-permission:dashboard.view');
        Route::get('/notifications/summary', [NotificationController::class, 'summary']);
        Route::get('/active-users', [ActiveUsersController::class, 'index'])->middleware(['admin-permission:sessions.view', 'mikrotik-unlocked']);
        Route::post('/active-users/reset-session', [ActiveUsersController::class, 'resetSession'])
            ->middleware(['admin-permission:sessions.view', 'mikrotik-unlocked']);
        Route::get('/bandwidth/summary', [BandwidthController::class, 'summary'])->middleware(['admin-permission:bandwidth.view', 'mikrotik-unlocked']);
        Route::get('/bandwidth/history', [BandwidthController::class, 'history'])->middleware(['admin-permission:bandwidth.view', 'mikrotik-unlocked']);
        Route::post('/bandwidth/capacity', [BandwidthController::class, 'updateCapacity'])
            ->middleware(['role:super_admin,admin', 'mikrotik-unlocked']);
        Route::get('/search', [SearchController::class, 'index']);

        Route::apiResource('routers', RouterController::class)
            ->only(['index', 'show'])->middleware(['admin-permission:routers.view', 'mikrotik-unlocked']);
        Route::apiResource('routers', RouterController::class)
            ->only(['store', 'update', 'destroy'])->middleware(['admin-permission:routers.manage', 'mikrotik-unlocked']);
        Route::post('/routers/{router}/test-connection', [RouterController::class, 'testConnection'])
            ->middleware(['admin-permission:routers.manage', 'mikrotik-unlocked']);
        Route::post('/routers/{router}/setup-guest-portal', [RouterController::class, 'setupGuestPortal'])
            ->middleware(['admin-permission:routers.manage', 'mikrotik-unlocked']);
        Route::get('/routers/{router}/isp-sessions', [IspSessionController::class, 'index'])
            ->middleware(['admin-permission:sessions.view', 'mikrotik-unlocked']);
        Route::post('/routers/{router}/isp-sessions/refresh', [IspSessionController::class, 'refresh'])
            ->middleware(['admin-permission:sessions.view', 'mikrotik-unlocked']);

        Route::middleware(['admin-permission:routers.manage', 'mikrotik-unlocked'])->prefix('router-management/{router}')->group(function () {
            Route::get('/overview', [MikrotikManagementController::class, 'overview']);
            Route::get('/users', [MikrotikManagementController::class, 'users']);
            Route::post('/users', [MikrotikManagementController::class, 'storeUser']);
            Route::patch('/users/{userId}', [MikrotikManagementController::class, 'updateUser']);
            Route::delete('/users/{userId}', [MikrotikManagementController::class, 'destroyUser']);
            Route::get('/hosts', [MikrotikManagementController::class, 'hosts']);
            Route::get('/bindings', [MikrotikManagementController::class, 'bindings']);
            Route::post('/bindings', [MikrotikManagementController::class, 'storeBinding']);
            Route::patch('/bindings/{bindingId}/status', [MikrotikManagementController::class, 'setBindingStatus']);
            Route::patch('/bindings/{bindingId}', [MikrotikManagementController::class, 'updateBinding']);
            Route::delete('/bindings/{bindingId}', [MikrotikManagementController::class, 'destroyBinding']);
            Route::get('/profiles', [MikrotikManagementController::class, 'profiles']);
            Route::post('/profiles', [MikrotikManagementController::class, 'storeProfile']);
            Route::patch('/profiles/{profileId}', [MikrotikManagementController::class, 'updateProfile']);
            Route::delete('/profiles/{profileId}', [MikrotikManagementController::class, 'destroyProfile']);
            Route::get('/dhcp-servers', [MikrotikManagementController::class, 'dhcpServers']);
            Route::get('/dhcp-leases', [MikrotikManagementController::class, 'dhcpLeases']);
            Route::post('/dhcp-leases/{leaseId}/make-static', [MikrotikManagementController::class, 'makeLeaseStatic']);
            Route::delete('/dhcp-leases/{leaseId}', [MikrotikManagementController::class, 'destroyLease']);
            Route::patch('/dhcp-leases/{leaseId}', [MikrotikManagementController::class, 'updateLease']);
            Route::get('/queues', [MikrotikManagementController::class, 'queues']);
            Route::post('/queues', [MikrotikManagementController::class, 'storeQueue']);
            Route::patch('/queues/{queueId}', [MikrotikManagementController::class, 'updateQueue']);
            Route::delete('/queues/{queueId}', [MikrotikManagementController::class, 'destroyQueue']);
            Route::get('/router-logs', [MikrotikManagementController::class, 'routerLogs']);
            Route::get('/address-lists', [MikrotikManagementController::class, 'addressLists']);
            Route::post('/address-lists', [MikrotikManagementController::class, 'storeAddressList']);
            Route::patch('/address-lists/{entryId}', [MikrotikManagementController::class, 'updateAddressList']);
            Route::delete('/address-lists/{entryId}', [MikrotikManagementController::class, 'destroyAddressList']);
            Route::get('/backups', [MikrotikManagementController::class, 'backups']);
            Route::post('/backups', [MikrotikManagementController::class, 'storeBackup']);
            Route::delete('/backups/{fileId}', [MikrotikManagementController::class, 'destroyBackup']);
            Route::get('/system-information', [MikrotikManagementController::class, 'systemInformation']);
            Route::post('/diagnostics', [MikrotikManagementController::class, 'diagnostic']);
            Route::post('/terminal', [MikrotikManagementController::class, 'terminal']);
            Route::get('/modules/{module}', [MikrotikManagementController::class, 'expandedModule']);
            Route::post('/modules/{module}', [MikrotikManagementController::class, 'storeExpanded']);
            Route::patch('/modules/{module}/{itemId}', [MikrotikManagementController::class, 'updateExpanded']);
            Route::delete('/modules/{module}/{itemId}', [MikrotikManagementController::class, 'destroyExpanded']);
            Route::get('/bridges', [MikrotikManagementController::class, 'bridges']);
            Route::post('/bridges', [MikrotikManagementController::class, 'storeBridge']);
            Route::patch('/bridges/{bridgeId}', [MikrotikManagementController::class, 'updateBridge']);
            Route::delete('/bridges/{bridgeId}', [MikrotikManagementController::class, 'destroyBridge']);
            Route::get('/bridge-ports', [MikrotikManagementController::class, 'bridgePorts']);
            Route::post('/bridge-ports', [MikrotikManagementController::class, 'storeBridgePort']);
            Route::patch('/bridge-ports/{portId}', [MikrotikManagementController::class, 'updateBridgePort']);
            Route::delete('/bridge-ports/{portId}', [MikrotikManagementController::class, 'destroyBridgePort']);
            Route::get('/bridge-hosts', [MikrotikManagementController::class, 'bridgeHosts']);
        });

        Route::apiResource('packages', App\Http\Controllers\Admin\PackageController::class)
            ->only(['index', 'show'])->middleware('admin-permission:packages.view');
        Route::apiResource('packages', App\Http\Controllers\Admin\PackageController::class)
            ->only(['store', 'update', 'destroy'])->middleware(['admin-permission:packages.manage', 'mikrotik-unlocked']);
        Route::apiResource('free-trials', FreeTrialCampaignController::class)
            ->parameters(['free-trials' => 'campaign'])->except('show')
            ->middleware(['admin-permission:packages.manage', 'mikrotik-unlocked']);

        Route::get('/customers', [CustomerController::class, 'index'])->middleware('admin-permission:customers.view');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])->middleware('admin-permission:customers.view');
        Route::delete('/customers/{customer}/test-data', [CustomerController::class, 'destroyTestData'])
            ->middleware('role:super_admin');
        Route::post('/customers/{customer}/hotspot-users/{hotspotUser}/reset-password', [CustomerController::class, 'resetHotspotPassword'])
            ->middleware(['admin-permission:customers.manage', 'mikrotik-unlocked']);

        // Unified purchases — replaces both /subscriptions and /orders
        Route::get('/purchases', [PurchaseController::class, 'index'])->middleware('admin-permission:transactions.paystack.view,transactions.momo.view');
        Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->middleware('admin-permission:transactions.paystack.view,transactions.momo.view');
        Route::middleware('admin-permission:purchases.assign')->group(function () {
            Route::post('/purchases/{purchase}/approve', [PurchaseController::class, 'approve']);
            Route::post('/purchases/{purchase}/reject', [PurchaseController::class, 'reject']);
            Route::post('/purchases/{purchase}/cancel', [PurchaseController::class, 'cancel']);
            Route::post('/purchases/{purchase}/suspend', [PurchaseController::class, 'suspend']);
            Route::post('/purchases/{purchase}/activate', [PurchaseController::class, 'activate']);
            Route::post('/purchases/{purchase}/retry-activation', [PurchaseController::class, 'retryActivation']);
            Route::post('/purchases/{purchase}/assign-voucher', [PurchaseController::class, 'assignVoucher']);
            Route::post('/purchases/assign', [PurchaseController::class, 'assign']);
        });

        Route::get('/vouchers', [VoucherController::class, 'index'])->middleware('admin-permission:vouchers.view');
        Route::get('/vouchers/inventory', [VoucherController::class, 'inventory'])->middleware('admin-permission:vouchers.view');
        Route::post('/vouchers/generate', [VoucherController::class, 'generate'])->middleware('admin-permission:vouchers.manage');
        Route::delete('/vouchers/{voucher}', [VoucherController::class, 'destroy'])->middleware('admin-permission:vouchers.manage');
        Route::post('/vouchers/{voucher}/check-mikrotik-status', [VoucherController::class, 'checkMikrotikStatus'])->middleware(['role:super_admin,admin', 'mikrotik-unlocked']);

        Route::middleware('role:super_admin,admin')->group(function () {
            Route::get('/vouchers/import', [VoucherImportController::class, 'index']);
            Route::post('/vouchers/import/upload', [VoucherImportController::class, 'upload']);
            Route::get('/vouchers/import/{batch}', [VoucherImportController::class, 'show']);
            Route::post('/vouchers/import/{batch}/confirm', [VoucherImportController::class, 'confirm']);
            Route::post('/vouchers/import/{batch}/cancel', [VoucherImportController::class, 'cancel']);
        });

        Route::get('/mikrotik-logs', [LogController::class, 'mikrotikLogs'])->middleware('mikrotik-unlocked');
        Route::get('/activity-logs', [LogController::class, 'activityLogs'])->middleware('admin-permission:logs.view');
        Route::get('/sms-logs', [LogController::class, 'smsLogs'])->middleware('admin-permission:logs.view');

        Route::get('/reports/revenue', [ReportController::class, 'revenue'])->middleware('admin-permission:transactions.paystack.view');
        Route::get('/reports/accounting', [ReportController::class, 'accounting'])
            ->middleware('admin-permission:reports.view');
        Route::get('/reports/payments', [ReportController::class, 'payments'])->middleware('admin-permission:transactions.paystack.view,transactions.momo.view');
        Route::get('/reports/customers', [ReportController::class, 'customers'])->middleware('admin-permission:customers.view');
        Route::get('/reports/router-activity', [ReportController::class, 'routerActivity'])->middleware('mikrotik-unlocked');

        Route::get('/settings', [SettingController::class, 'index'])->middleware('role:super_admin');
        Route::put('/settings', [SettingController::class, 'update'])->middleware('role:super_admin');
        Route::get('/operating-mode', [SettingController::class, 'operatingMode'])->middleware('role:super_admin,admin');
        Route::put('/operating-mode', [SettingController::class, 'updateOperatingMode'])->middleware('role:super_admin,admin');

        Route::get('/complaints', [ComplaintController::class, 'index'])->middleware('admin-permission:complaints.view');
        Route::get('/complaints/{complaint}', [ComplaintController::class, 'show'])->middleware('admin-permission:complaints.view');
        Route::post('/complaints/{complaint}/respond', [ComplaintController::class, 'respond'])->middleware('admin-permission:complaints.manage');
    });

    // ── Customer ────────────────────────────────────────────────
    Route::middleware(['auth:customer', 'abilities:customer'])->prefix('customer')->group(function () {
        Route::post('/logout', [CustomerAuthController::class, 'logout']);
        Route::get('/me', [CustomerAuthController::class, 'me']);
        Route::get('/free-trial', [FreeTrialController::class, 'offer']);
        Route::post('/free-trial/claim', [FreeTrialController::class, 'claim'])
            ->middleware('throttle:login');

        Route::get('/dashboard', [App\Http\Controllers\Customer\DashboardController::class, 'index']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::post('/profile/wifi-password', [ProfileController::class, 'resetWifiPassword'])
            ->middleware('throttle:wifi-password-reset');
        Route::post('/hotspot/sessions/prepare', [HotspotSessionController::class, 'prepare'])
            ->middleware('throttle:hotspot-connect');
        Route::get('/hotspot/sessions/current', [HotspotSessionController::class, 'current'])
            ->middleware('throttle:hotspot-status');
        Route::get('/hotspot/sessions/{session}', [HotspotSessionController::class, 'status'])
            ->middleware('throttle:hotspot-status');

        // Unified purchases — replaces PaymentController + OrderController
        Route::get('/purchases', [App\Http\Controllers\Customer\PurchaseController::class, 'index']);
        Route::post('/purchases', [App\Http\Controllers\Customer\PurchaseController::class, 'store']);
        Route::get('/purchases/{reference}', [App\Http\Controllers\Customer\PurchaseController::class, 'show']);
        Route::get('/purchases/{reference}/status', [App\Http\Controllers\Customer\PurchaseController::class, 'status']);
        Route::post('/purchases/{reference}/acknowledge-payment', [App\Http\Controllers\Customer\PurchaseController::class, 'acknowledgePayment']);
        Route::post('/purchases/{reference}/verify', [App\Http\Controllers\Customer\PurchaseController::class, 'verify'])
            ->middleware('throttle:order-verify');
        Route::post('/purchases/{reference}/verify-paystack', [App\Http\Controllers\Customer\PurchaseController::class, 'verifyPaystack'])
            ->middleware('throttle:order-verify');
        Route::post('/purchases/{purchase}/activate', [App\Http\Controllers\Customer\PurchaseController::class, 'activate']);
        Route::post('/purchases/{purchase}/connect', [App\Http\Controllers\Customer\PurchaseController::class, 'connect']);

        Route::post('/vouchers/redeem', [App\Http\Controllers\Customer\VoucherController::class, 'redeem'])
            ->middleware('throttle:voucher-redeem');
        Route::get('/my-vouchers', [App\Http\Controllers\Customer\VoucherController::class, 'myVouchers']);

        Route::get('/complaints', [App\Http\Controllers\Customer\ComplaintController::class, 'index']);
        Route::post('/complaints', [App\Http\Controllers\Customer\ComplaintController::class, 'store']);
    });
});
