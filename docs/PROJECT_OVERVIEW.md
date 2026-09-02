# Hotspot Billing System — Project Overview

## What this is

A full-stack billing system for a MikroTik-powered internet hotspot business.
A customer connects to the hotspot, registers or logs in, picks a package,
pays via Paystack, and the backend automatically provisions their access on
the MikroTik router over a WireGuard tunnel — no manual intervention needed
for the common path.

## Stack

| Layer      | Choice                                             |
|------------|-----------------------------------------------------|
| Backend    | Laravel API (PHP 8.2+)                               |
| Frontend   | React + Vite + Tailwind CSS                          |
| Database   | MySQL                                                |
| Auth       | Laravel Sanctum (token-based, two guards: admin/customer) |
| Payments   | Paystack                                             |
| Router API | MikroTik RouterOS API, over WireGuard VPN, API-SSL (8729) |
| Server     | Ubuntu VPS (Nginx, Supervisor, Laravel queue + scheduler) |

## Core flow

1. Customer connects to hotspot → redirected to captive portal (React app).
2. Registers or logs in.
3. Picks an internet package.
4. Pays via Paystack.
5. Paystack confirms payment (webhook + redirect-verify, both funnel through
   the same idempotent activation path).
6. Backend queues a job to create/enable the customer's MikroTik hotspot
   user with the package's speed/data profile.
7. Customer is online.
8. A scheduled command expires subscriptions and disables MikroTik users
   once `expires_at` (plus any configured grace period) has passed.

## Design decisions carried over from the review pass

These aren't in the original phase prompts verbatim — they were flagged
during spec review and are baked into the code from Phase 1 onward rather
than retrofitted later:

- **No plaintext customer passwords anywhere.** The hotspot Wi-Fi password
  is a separate, randomly generated secret stored only in `hotspot_users`,
  distinct from the customer's account login password.
- **MikroTik calls are never made synchronously inside the Paystack webhook
  handler.** Activation is queued (`ActivateHotspotUserJob`) so the webhook
  returns fast and RouterOS/WireGuard slowness or downtime can't cause
  Paystack to consider the webhook delivery failed.
- **Activation is idempotent.** Both the webhook and the "verify on
  redirect" path call the same `SubscriptionService::activateSubscription()`,
  which locks the row and no-ops if already active.
- **Sensitive fields are redacted before writing to `mikrotik_logs`** (e.g.
  a hotspot user's password is never persisted in the log table).
- **API routes are versioned** under `/api/v1` from day one.
- **Two Sanctum guards**, not one role column shared across tables — admins
  (`users` table) and customers (`customers` table) are structurally
  different actors and should not share a single polymorphic auth guard.
  See `API_SPEC.md` for the guard config.

## Open design question: subscription stacking/renewal

Not yet fully resolved. What should happen if a customer tries to buy a
new package while they already have an active subscription (on the same
router)? Options: stack (extend `expires_at`), queue for after the current
one expires, or reject outright.

**Phase 8 ships a temporary, conservative rule**: `PaymentController::initialize()`
rejects the purchase outright with a 422 if the customer already has an
active subscription on the requested router. This avoids an ambiguous
double-active state, but isn't necessarily the desired product behavior
long-term (a customer wanting to top up before expiry, for instance, can't
right now). Revisit once Phase 9 (activation) and Phase 10 (expiry) are
both in place and the actual desired renewal UX is decided.

See `DATABASE_SCHEMA.md` for schema changes made for the same reasons
(soft deletes, composite indexes, a `router_package_profiles` pivot, etc.)
and `PRODUCTION_SECURITY.md` for the deployment-time hardening steps.

## Buy Internet and Buy Voucher were merged into one flow

Originally built as two separate customer-facing pages/nav items — a
Paystack ("card") flow and a MoMo-voucher flow — because they're backed
by genuinely different provisioning mechanisms:

- **Paystack path**: customer pays, the app automatically creates a
  MikroTik hotspot user in real time (`SubscriptionService`,
  `ActivateHotspotUserJob`).
- **MoMo path**: customer pays via direct transfer, SMS-verified, and
  receives a voucher code that was already generated on MikroTik ahead
  of time by the admin — the app never provisions anything for this path.

That difference is a backend/business concern, not something a customer
should have to navigate around, so the two pages were merged into one
(`BuyInternet.jsx`). `internet_packages.sales_channel` now drives the
UI directly: `subscription` → only the card option is shown, `voucher` →
only Mobile Money, `both` → the customer is asked which they'd like to
use. `GET /customer/packages` returns every active package regardless of
channel (the old channel-filtered version, and the separate
`GET /customer/voucher-packages` endpoint, were retired).

**This surfaced a real gap while merging**: the admin package
create/edit form never actually exposed `sales_channel` at all — every
package created through the UI silently defaulted to `subscription`-only,
with no way for an admin to enable Mobile Money sales for a package short
of editing the database directly. Fixed by adding the field to
`PackageFormModal.jsx` and the backend's `PackageRequest` validation
(`description` was found missing from the same form at the same time,
for the same underlying reason — added together).
