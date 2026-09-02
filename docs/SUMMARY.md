# Royal WiFi Hotspot Billing System — Project Handoff Summary

**Purpose of this document**: a complete, current-as-of-now summary for another AI (or developer) picking up this project cold. The `docs/` folder has several other files (`PROJECT_OVERVIEW.md`, `DATABASE_SCHEMA.md`, `API_SPEC.md`, etc.) written incrementally during earlier phases — **treat those as historically useful but potentially stale**. This document supersedes them on anything they disagree about, since it reflects the actual current state of the code.

---

## 1. What this system is

A billing and access-management platform for a WiFi hotspot business (MikroTik-based) operating in Ghana. Customers connect to a physical hotspot, pay (via Paystack card or MTN Mobile Money), and get internet access — either a real-time-provisioned username/password, or a pre-generated voucher code, depending on the router.

Two customer entry points exist:
- **Registered customers** — full accounts, dashboard, purchase history, "Connect to WiFi" one-tap login.
- **Guests** — no account at all, pay via MoMo, get a single 6-character code, recover it later via phone number.

An admin panel manages routers, packages, customers, purchases, vouchers, complaints, bandwidth, and system settings.

---

## 2. Tech stack

- **Backend**: Laravel 11, PHP 8.2+, MySQL, Sanctum (dual guards: `admin` via `users` table, `customer` via `customers` table)
- **Frontend**: React + Vite + Tailwind, single SPA serving three distinct experiences (admin, customer, guest) via route-based access control
- **MikroTik integration**: `evilfreelancer/routeros-api-php`, over a WireGuard tunnel
- **Payments**: Paystack (Guzzle HTTP calls) + MTN MoMo via SMS-detection (Android "SMS Forwarder" app relays SMS to a webhook)
- **PDF**: `barryvdh/laravel-dompdf` (added late — **not yet composer-installed**, see §8)
- **Charts**: `recharts` (npm-installed, lazy-loaded so it doesn't bloat the shared bundle — see `App.jsx`)

Repo root: `hotspot-billing/` with `backend/` and `frontend/` subfolders, plus `docs/` and `deploy/`.

---

## 3. Core architectural decisions (the ones that aren't obvious from reading code alone)

These are the decisions that took the longest to arrive at and most shape the codebase. Read this section before changing anything payment- or fulfillment-related.

### 3.1 Unified Purchase record
There is **no** `Subscription` or `Order` model anymore — both were collapsed into one `Purchase` model/table. Old files (`Subscription.php`, `Order.php`, `SubscriptionService.php`, `VoucherAssignmentService.php`, old controllers) are **still present in the repo as dead reference code, not routed to anywhere**. Don't resurrect them; extend `Purchase`/`PurchaseService` instead.

`Purchase` has two independent dimensions:
- `payment_method`: `paystack` | `momo` | `admin_grant`
- `fulfillment_type`: `live` | `voucher`

These are **not correlated**. A Paystack payment can result in a voucher (manual router). A MoMo payment can result in a live username/password (live router). Fulfillment is decided purely by which router was chosen, at confirmation time, in `PurchaseService::fulfill()`.

### 3.2 Global payment gateway toggle
Only one of Paystack/MoMo is "active" system-wide at a time — a single `SystemSetting` key (`active_payment_method`), configured in **Settings → Payment Gateway** tab. The customer never picks a payment method; `Customer\PurchaseController::store()` reads the active setting and routes accordingly. `sales_channel` on `InternetPackage` **is now dead weight** — it exists in the schema, has a form field nobody uses anymore (replaced by "Available to Guests"), and must never be used to gate anything. **This has already caused three real bugs this session** (see §7) — if you find code checking `sales_channel`, it's almost certainly a bug, not intentional.

### 3.3 Router decides fulfillment
Every `Router` has `connection_mode`: `live` | `manual`.
- **Live**: real WireGuard connection to a physical MikroTik. `MikrotikService` makes real API calls. Purchases here get a real-time-created hotspot user (`ActivateHotspotUserJob`).
- **Manual**: no live connection at all. Admin generates voucher codes independently (either by clicking "Generate Vouchers," which now also creates them live via `MikrotikService`, or by physically creating them on the router and importing a PDF export). `MikrotikService::run()` has a central guard (`$this->router->isManual()`) that short-circuits every live call with a clean failure — **this one guard is why most of the codebase doesn't need per-call-site manual-mode checks.**

### 3.4 Purchase queueing (one login per customer per router)
If a customer already has an active purchase on a router and buys/redeems another one for the *same* router, the second purchase is created and paid normally but held at `status = 'queued'` — `PurchaseService::fulfill()`'s gate (`hasActiveOnSameRouter()`). The customer sees an "Activate" button in My Vouchers → Payment History, blocked with a clear message while the first purchase is still active. When the first purchase expires, `expirePurchase()` **automatically fulfills the next queued one** on that same router — no customer action needed in the common case.

**Explicitly rejected**: a "new device" concurrent-login bypass (would have let a second purchase activate on a genuinely separate simultaneous login while the first was still active). Built once, then explicitly reverted per instruction — don't re-add multi-login support to `hotspot_users` without re-confirming this is wanted.

### 3.5 Guest checkout
No account, MoMo-only, live-routers-only (a manual router has no way to generate a code on the spot). Entry point: `Portal.jsx` — the actual landing page a device sees after the MikroTik login redirect (`MikrotikLoginPageController` generates this redirect target). Portal offers "I have an account" (→ login) or "Buy as guest" (→ `GuestBuy.jsx`). Guest gets a single 6-char code (`Purchase::generateGuestCode()`), used as both username and password, generated live via `ActivateHotspotUserJob::activateGuest()`. Recovery: phone-number lookup (`GuestPurchaseController::lookup()`) + browser localStorage for same-device convenience. "Activate"/auto-login is a literal client-side form POST straight to the router's `link-login-only` URL — cannot be done server-side, RouterOS ties hotspot auth to which device on its own LAN sent the request.

### 3.6 MikroTik portal takeover
Admin panel has a "Set Up Guest Portal" button (`Admin\RouterController::setupGuestPortal()`) that:
1. Adds walled-garden entries for **both** the backend host and the frontend host (these are genuinely different hosts/ports in this setup — see §6 on local testing). **This was a real bug fixed late in the session** — only the backend host was ever whitelisted for a while, silently blocking every guest page load.
2. Tells the router to `/tool fetch` its own new `login.html` from `MikrotikLoginPageController`, which redirects to `Portal.jsx`.

Uses a **second, separate RouterOS API credential** (`provisioning_api_username`/`provisioning_api_password` on `Router`) with `/tool fetch` + file permissions — deliberately never granted to the regular hotspot-management credential, which stays locked down to `api,read,write` only (see `docs/PRODUCTION_SECURITY.md` and the `/user group` policy used when setting up routers).

### 3.7 Bandwidth tracking
`bandwidth_logs` table, populated by `SnapshotBandwidthUsage` (scheduled every 5 min). RouterOS reports **cumulative** session bytes, not deltas — the command computes the delta since the last poll (tracked via `last_bytes_in`/`last_bytes_out`/`last_polled_at` on `HotspotUser`) and adds only the increment to today's log row. This avoids double-counting or misattributing a session that spans midnight.

---

## 4. Full feature list

### Admin panel
Dashboard (merged revenue, bandwidth cards, purchases-by-status at top, real charts via recharts, active-users panel) · Routers (live/manual toggle, Test Connection, Set Up Guest Portal, provisioning credentials) · Customers (search/filter, CSV→**PDF** export) · Packages (router-profile mapping, Available to Guests toggle) · Purchases (unified table, all actions: approve/reject/cancel/suspend/activate/retry-activation/assign-voucher) · Payments (Paystack-only transaction log) · Vouchers (inventory, **live-provisioning** Generate button, PDF Import) · Assign Package (free grant, no payment) · Bandwidth (today/month totals, per-user, history by day/month) · Active Sessions (kick a connected device) · Complaints (respond/resolve) · Logs (MikroTik/activity/SMS) · Settings (tabbed: Business/Gateway/MoMo/System, includes Telegram bot config) · Notification bell (badge counts + activity feed, polls every 30s) · Global search (topbar)

### Customer app
Register/Login · Buy Internet (single flow, gateway is invisible/automatic, confirm modal before buying) · Payment Page (MoMo instructions or Paystack redirect, collapsible "Already paid?" section, SMS Forwarder status shown, dedicated success view for `queued` purchases) · Dashboard (live credentials or voucher, countdown timer, bandwidth today/total, "Connect to WiFi" one-tap button) · My Vouchers (vouchers + full purchase history, Activate button for queued purchases) · Redeem Voucher · Payments (history, sourced from `Purchase` not `Payment` — covers both gateways) · Complaints (modal-based submission) · Profile

### Guest flow
`/portal` → `/guest/buy` → `/guest/payment/:reference` → code shown + literal auto-login button · `/guest/recover` (phone lookup + local-storage shortcut)

---

## 5. Key files to know

```
backend/app/Services/PurchaseService.php          — the fulfillment core, read this first
backend/app/Services/MikrotikService.php           — central manual-mode guard in run()
backend/app/Models/Purchase.php                    — the unified model
backend/app/Jobs/ActivateHotspotUserJob.php         — queued, does the actual MikroTik provisioning
backend/routes/api.php                              — ~80 routes, organized by admin/customer/guest prefix
backend/app/Console/Commands/                       — ExpirePurchases, SnapshotBandwidthUsage, MonitorRouterHealth, purchases:expire etc, all scheduled in bootstrap/app.php
frontend/src/App.jsx                                — route map, note the lazy-loaded AdminDashboard
frontend/src/pages/customer/BuyInternet.jsx         — the unified buy flow
frontend/src/pages/guest/                           — guest-only pages, no auth, separate axios instance (guestApi.js)
frontend/src/components/Sidebar.jsx                 — grouped nav sections, shared between admin/customer layouts
docs/PRODUCTION_SECURITY.md                         — the two-API-user RouterOS setup, still accurate
```

---

## 6. Local testing setup (current, real, in progress)

**No VPS yet.** The admin is testing against a real physical MikroTik (RB951Ui-2HnD, RouterOS 7.18.2, **MIPSBE architecture — cannot run RouterOS containers**, ruling out Tailscale/ZeroTier on-router). The router is behind CGNAT (no public IP) confirmed via its own WAN address being itself private (`192.168.100.x`).

**Current setup**: a direct, LAN-local WireGuard tunnel — router acts as WireGuard server (`wg1` interface, tunnel IP `10.20.0.1`), dev machine as client (tunnel IP `10.20.0.2`), reachable only because both are on the same physical network right now. This is explicitly a stand-in for a future VPS-hub setup (same tunnel-IP concept, `.env` values won't need code changes later, just new IPs).

`.env` values in play:
```
APP_URL=http://10.20.0.2:8000       # dev machine's own tunnel IP — router reaches back to this
FRONTEND_URL=http://<LAN-IP>:5173   # dev machine's real LAN IP — guest devices reach this, NOT the tunnel IP
```
These map to `config('services.backend.url')` / `config('services.frontend.url')` in `config/services.php` — **note: `config/app.php` was never published in this project**, so don't rely on `config('app.url')` anywhere; it won't resolve as expected.

RouterOS API-SSL is set up with a genuinely self-signed cert requiring `key-usage=key-cert-sign,crl-sign,tls-server` (all three, or `/certificate sign` fails with "CA not found" — a real gap in the original setup instructions, corrected mid-session). Dedicated `api-admin` user in a locked-down `api-only` group exists on the router.

**Not yet set up as of this document**: the second provisioning credential needed for "Set Up Guest Portal" to actually run.

---

## 7. Known bugs found and fixed this session (context for why some code looks defensive)

- **Three separate `sales_channel` gate bugs** — `CreateOrderRequest`, `Admin\VoucherController::inventory()`, and the `InternetPackage` scopes themselves all still filtered by `sales_channel` after it was supposed to be retired, silently blocking MoMo purchases and hiding voucher inventory for every package. The two scope methods were removed entirely (not just unused) since this exact pattern recurred three times.
- **`PurchaseController::activate()` missing `use App\Services\PurchaseService;`** — caused "Class ... does not exist" errors resolved against the wrong namespace. Easy to reintroduce if a new service is type-hinted without checking the import exists.
- **Admin `destroy()` guards on Package/Router used `status === 'active'`** instead of the `Purchase::active()` scope (which also covers `voucher_assigned`/`completed`) — would have let a package/router be deleted while still in use by a voucher-fulfilled purchase.
- **`SubscriptionService::expireSubscription()`** (pre-unification) marked `hotspot_user.disabled = true` regardless of whether the MikroTik call actually succeeded — fixed when porting to `PurchaseService::expirePurchase()`.
- **Guest portal walled garden only whitelisted the backend host**, never the frontend — guest pages silently failed to load entirely. Fixed to whitelist both.
- **"Generate Vouchers" never actually called MikroTik** — created database-only codes that wouldn't work on the real router. Fixed to create a genuine live hotspot user per code; manual routers now rejected outright with a redirect to PDF Import.

---

## 8. Things that need action before they'll work

1. **`composer require barryvdh/laravel-dompdf`** — added to `composer.json` but never installed (this sandbox has no Packagist access to verify it). Needed for Customer PDF export.
2. **`npm install`** — `recharts` was added; make sure `node_modules` is current.
3. **Run all pending migrations** — a large number were added this session (unified `purchases` table, `bandwidth_logs`, `complaints`, guest fields, `queued` status, `admin_grant` payment method, provisioning credentials on `routers`, etc.). `php artisan migrate`.
4. **Second provisioning API user** on the router, per §6, before "Set Up Guest Portal" will work.
5. **Telegram bot token/chat ID** in Settings if router-offline alerts are wanted (optional — silently no-ops if unconfigured).

---

## 9. Dead code, left intentionally as reference

`Subscription.php`, `Order.php`, `SubscriptionService.php`, `VoucherAssignmentService.php`, old `Customer\PaymentController`/`OrderController`/`SubscriptionController`, old `Admin\SubscriptionController`/`OrderController`. None of these are routed to. Safe to delete, but were kept in case old logic needed referencing during the migration — nothing currently depends on them.