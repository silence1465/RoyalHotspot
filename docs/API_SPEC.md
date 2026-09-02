# API Spec

Base URL: `/api/v1`

## Auth model

Two Sanctum-backed actors — `App\Models\User` (admin/support staff) and
`App\Models\Customer` — each with their own token type. Two Laravel auth
**guards** (`admin` / `customer`) exist in `config/auth.php` for
ergonomics (`$request->user('admin')`, `Auth::guard('customer')`), but
**they are not the actual security boundary**: Sanctum's guard resolves
the authenticated user from the token's polymorphic `tokenable` relation
regardless of which guard/provider a route checks against, so a customer
token would technically satisfy an `auth:admin` check on its own.

The real separation is **token abilities**. Every token is issued with a
single ability:

```php
$user->createToken('admin-token', ['admin'])->plainTextToken;
$customer->createToken('customer-token', ['customer'])->plainTextToken;
```

...and every protected route group checks it:

```php
Route::middleware(['auth:admin', 'abilities:admin'])->prefix('admin')->group(...);
Route::middleware(['auth:customer', 'abilities:customer'])->prefix('customer')->group(...);
```

Note it's `auth:admin` / `auth:customer` here, not `auth:sanctum` — Laravel
needs a guard literally named `sanctum` to exist in `config('auth.guards')`
for `auth:sanctum` to resolve at all, and this project never defines one
(only `admin` and `customer`, both using the `sanctum` driver). An earlier
version of this codebase used `auth:sanctum` in the routes, which threw a
guard-resolution error on every single authenticated request — fixed by
pointing at the guards that actually exist. Both guards behave identically
for token resolution (Sanctum ignores the guard's `provider` when
resolving a token, as explained above) — the `abilities` check is what
does the real separation either way.

`abilities` / `ability` middleware aliases are registered in
`bootstrap/app.php` (Sanctum ships the underlying
`CheckAbilities`/`CheckForAnyAbility` classes but Laravel 11's
`bootstrap/app.php` style requires them to be aliased explicitly — they
aren't auto-registered).

## Admin endpoints

Login is throttled (`throttle:login` — 6/min per IP, registered in
`AppServiceProvider`) against brute-force and credential-stuffing attempts.

| Method | Path                                  | Notes |
|--------|----------------------------------------|-------|
| POST   | /admin/login                          | throttled |
| POST   | /admin/logout                         | |
| GET    | /admin/me                             | |
| GET    | /admin/dashboard/stats                | |
| GET/POST/PUT/DELETE | /admin/routers[/{id}]    | |
| POST   | /admin/routers/{id}/test-connection   | |
| GET    | /admin/customers                      | **Added in Phase 12** — the admin Customers nav item existed since Phase 4 but had no backend behind it until now (Phase 7 only built the customer's own self-service endpoints) |
| GET    | /admin/customers/{id}                 | Added in Phase 12, alongside the above — subscription + payment history for one customer |
| GET/POST/PUT/DELETE | /admin/packages[/{id}]   | |
| GET    | /admin/subscriptions                  | filter by status |
| GET    | /admin/subscriptions/{id}             | |
| POST   | /admin/subscriptions/{id}/suspend     | |
| POST   | /admin/subscriptions/{id}/activate    | |
| POST   | /admin/subscriptions/{id}/cancel      | |
| POST   | /admin/subscriptions/{id}/retry-activation | manual retry when `pending_activation` |
| GET    | /admin/vouchers                       | |
| POST   | /admin/vouchers/generate              | |
| DELETE | /admin/vouchers/{id}                  | |

### Royal WiFi extension — PDF voucher import (Extension Phase 3)

| Method | Path                                   | Notes |
|--------|------------------------------------------|-------|
| GET    | /admin/vouchers/import                 | Import batch history, paginated |
| POST   | /admin/vouchers/import/upload           | Multipart, fields `pdf` (required), `router_id` (optional — see Extension Phase 5 note below). Extracts + parses immediately, creates a `pending_review` batch, returns the preview — does NOT create any voucher rows yet |
| GET    | /admin/vouchers/import/{batch}          | Batch detail (includes the stored preview in `extraction_meta`) |
| POST   | /admin/vouchers/import/{batch}/confirm | Body (optional): `package_overrides` (`{section_index: package_id}`), `excluded_codes` (array). Re-checks duplicates live against the DB rather than trusting the upload-time preview snapshot. Locks the batch row (`lockForUpdate`) so double-clicking Confirm can't double-import |
| POST   | /admin/vouchers/import/{batch}/cancel  | Only valid while `pending_review` |

`VoucherPdfExtractionService` tries native PDF text extraction first
(`smalot/pdfparser`); OCR (`pdftoppm` + `tesseract` CLI tools, gated
behind `config('voucherimport.ocr_enabled')` and a runtime check that
both binaries actually exist) only kicks in if that yields under ~40
characters, i.e. the PDF looks scanned/image-based. See
`config/voucherimport.php` for every tunable — code regex, package
header regex, size limits — since real MikroTik-export PDF formats will
likely need adjusting without a code change.

### Royal WiFi extension — payment management (Extension Phase 4)

| Method | Path                                   | Notes |
|--------|------------------------------------------|-------|
| GET    | /admin/orders                          | Filter by `status`, search by `reference`/`momo_transaction_id` |
| GET    | /admin/orders/{id}                     | Full detail including matched SMS logs |
| POST   | /admin/orders/{id}/approve             | Manual verification — runs the same `VoucherAssignmentService` as the automatic paths, just with `verification_method=admin_manual` |
| POST   | /admin/orders/{id}/reject              | Body: `reason` (required). Terminal — sets `status=failed` |
| POST   | /admin/orders/{id}/cancel              | Only valid for `pending`/`processing` orders |
| POST   | /admin/orders/{id}/assign-voucher      | Manual retry for an order stuck `verified` with no voucher (inventory ran out at payment time) |

### Royal WiFi extension — MikroTik status check (Extension Phase 5)

| Method | Path                                              | Notes |
|--------|-----------------------------------------------------|-------|
| POST   | /admin/vouchers/{id}/check-mikrotik-status        | On-demand, single voucher. Reuses the existing `MikrotikService` (no new connection logic) to check whether the code exists as a hotspot user on its router and whether it's actually been used (RouterOS session uptime/bytes-in). Requires the voucher to have a `router_id` — see `voucher_import_batches.router_id`, captured at upload time via the optional `router_id` field. Works regardless of `VOUCHER_MIKROTIK_SYNC_ENABLED` — this is the safe way to validate the code-equals-RouterOS-username assumption against one real voucher before ever enabling bulk sync. |

`php artisan vouchers:sync-mikrotik-status [--router=ID]` — bulk version
of the same check, opt-in only (`VOUCHER_MIKROTIK_SYNC_ENABLED=true`),
deliberately not on the scheduler until the assumption above is confirmed
against a real deployment.

### Royal WiFi extension — admin frontend support (Extension Phase 7)

| Method | Path                          | Notes |
|--------|---------------------------------|-------|
| GET    | /admin/vouchers/inventory     | Per-package available/reserved/assigned/used/expired/invalid counts + low-stock flag. Threshold is `system_settings.low_stock_threshold`, not hardcoded. |
| GET    | /admin/sms-logs               | Every SMS the forwarder relayed, matched or not — filter by `status`, search by transaction ID/reference/sender |

`GET /admin/dashboard/stats` (existing endpoint, extended) now includes a
`royal_wifi` key: sales totals (today/week/month/all-time, from
`orders.status IN (completed, voucher_assigned)`), order counts by
status, a `low_stock_package_count` for the dashboard banner, count of
customers who have ever received a voucher (either flow), and recent
orders/imports. Nested separately so the original dashboard payload shape
is unchanged for anything already reading it.

| Method | Path                                  | Notes |
|--------|----------------------------------------|-------|
| GET    | /admin/mikrotik-logs                  | request/response payloads redacted |
| GET    | /admin/activity-logs                  | |
| GET    | /admin/reports/revenue                | |
| GET    | /admin/reports/payments               | supports `?export=csv`; frontend fetches as a blob through the authenticated axios instance rather than a raw `window.open()`/navigation, since Sanctum here is header-based token auth — a plain browser navigation can't attach the `Authorization` header |
| GET    | /admin/reports/customers              | supports `?export=csv`, same auth caveat as above; also accepts `?search=` |
| GET    | /admin/reports/router-activity        | |
| GET/PUT | /admin/settings                      | |

## Customer endpoints

| Method | Path                              | Notes |
|--------|-------------------------------------|-------|
| POST   | /customer/register                 | throttled (`throttle:login`) |
| POST   | /customer/login                    | throttled (`throttle:login`); accepts username OR phone |
| POST   | /customer/logout                   | |
| GET    | /customer/me                       | |
| GET    | /customer/packages                 | active packages only |
| GET    | /customer/dashboard                | Deliberately reveals the customer's own hotspot password (`HotspotUser.password` is `$hidden` everywhere else — this is the one legitimate exception, via explicit `makeVisible()`, not a model-wide change) |
| GET    | /customer/subscription             | |
| GET    | /customer/payments                 | |
| PUT    | /customer/profile                  | |
| POST   | /customer/payments/initialize      | body: `package_id`, `router_id`. Validates the package has a `router_package_profiles` mapping for that router (see `InitializePaymentRequest`). Rejects with 422 if the customer already has an active subscription on that router (temporary rule — see "Open design question" in `PROJECT_OVERVIEW.md`) |
| POST   | /customer/vouchers/redeem           | rate-limited (`throttle:voucher-redeem`); if the voucher has no fixed router, responds 422 with `requires_router_selection: true` + `available_routers` instead of a bare validation error, so the frontend can render a picker in one round trip. Creates a zero-amount `provider: 'voucher'` payment row for audit-trail consistency — `Payment::successful()` sums real revenue, so this never inflates reports. |

### Royal WiFi extension — voucher purchase (Extension Phase 4)

| Method | Path                                        | Notes |
|--------|-----------------------------------------------|-------|
| GET    | /customer/voucher-packages                  | Voucher-channel packages only (`sales_channel` = voucher/both) — separate from `/customer/packages`, see `docs/DATABASE_SCHEMA.md` on why these two listings are kept apart |
| GET    | /customer/orders                            | **Added in Extension Phase 6** — the customer's own order history, needed for the My Vouchers/Payment History page. Distinct from `/customer/payments` (Paystack) |
| GET    | /customer/my-vouchers                       | Unifies vouchers from both the original redeem flow and the new order-assignment flow |
| POST   | /customer/orders                            | Body: `package_id`. Creates the order + reference; does NOT touch voucher inventory at all |
| GET    | /customer/orders/{reference}                | Payment page data — package, amount, MoMo number/account name (from `system_settings`, never hardcoded), status |
| GET    | /customer/orders/{reference}/status         | Lightweight poll endpoint — status + `has_voucher` only, meant to be called repeatedly |
| POST   | /customer/orders/{reference}/acknowledge-payment | Cosmetic "I have made payment" click — flips `pending`→`processing`. Does not verify anything |
| POST   | /customer/orders/{reference}/verify         | The "Already Paid" fallback. Body: `transaction_id`, `reference_used`. Rate-limited (`throttle:order-verify`) |

All five `/customer/orders/*` routes look an order up by **reference**,
scoped to `where('customer_id', $request->user()->id)` — never a bare ID
lookup, so a customer can't enumerate or query another customer's order
by guessing IDs.

## Webhook

| Method | Path                    | Notes |
|--------|--------------------------|-------|
| POST   | /webhooks/paystack       | HMAC-verified via `X-Paystack-Signature`, not Sanctum-authenticated |
| POST   | /webhooks/sms-payment    | Royal WiFi extension. Token-authenticated (`sms-forwarder` middleware — shared secret via `Authorization: Bearer` or `X-Forwarder-Token` header, timing-safe `hash_equals` comparison), rate-limited (`throttle:sms-webhook`). Body: `message` (required), `from`/`to`/`timestamp` (optional). See `SmsBodyParser` for how the SMS text itself gets parsed, and `PaymentMatchingService` for how a parsed SMS gets matched to a pending order. |

The webhook handler must:
1. Verify signature against the **raw** request body.
2. Look up the payment by reference, `lockForUpdate()`, and check it isn't
   already `successful` before processing (idempotency).
3. Also call `PaystackService::verifyTransaction()` as defense-in-depth —
   don't trust the webhook payload's amount/status field alone.
4. Dispatch `ActivateHotspotUserJob` rather than calling `MikrotikService`
   directly.

`SubscriptionService::activateSubscription()` also flips the customer's
`status` to `active` and sets `current_subscription_id` — this lives in
the activation logic itself (not the webhook controller) so every
activation path (webhook, admin retry, future voucher redemption in
Phase 11) gets it consistently.

`POST /admin/subscriptions/{id}/retry-activation` only re-dispatches the
MikroTik provisioning job — it deliberately does NOT re-run
`activateSubscription()`, which would recompute `starts_at`/`expires_at`
from `now()` and silently extend the customer's paid-for duration a
second time. The billing period is fixed at first activation; only the
provisioning step is retried.

## Subscription status values

Extended beyond the original spec to support the retry-activation flow:

`pending` → `active` → `expired` | `cancelled` | `suspended`

Plus **`pending_activation`**: payment succeeded but the queued MikroTik
job failed after retries. Surfaced to admins via
`GET /admin/subscriptions?status=pending_activation` and resolved with
`POST /admin/subscriptions/{id}/retry-activation`.
