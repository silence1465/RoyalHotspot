# Database Schema

Changes from the original spec are marked **[CHANGED]** or **[NEW]** with
the reason inline. Full migrations are built in Phase 2 — this doc is the
source of truth for the shape.

## users (admin/staff)
- id
- name
- email (unique)
- phone
- password (hashed)
- role: super_admin, admin, support
- status: active, inactive
- timestamps
- **[NEW]** `deleted_at` — soft delete, staff accounts shouldn't hard-delete

## customers
- id
- full_name
- phone (unique)
- email nullable
- username (unique)
- status: active, inactive, suspended
- current_subscription_id nullable
- timestamps
- **[NEW]** `deleted_at` — soft delete
- ~~password_text~~ **[REMOVED]** — plaintext password storage was a real
  liability for a field only needed to display a hotspot Wi-Fi password.
  The hotspot password is a separate random secret, stored only in
  `hotspot_users.password`. `customers` keeps only a normal hashed
  `password` column for account login.

## routers
- id
- name
- location
- router_ip
- wireguard_ip
- api_username
- api_password (**encrypted** cast — Laravel's `encrypted` attribute cast,
  decrypted only inside `MikrotikService`)
- api_port default 8729
- api_ssl boolean default true
- status: online, offline, maintenance
- timestamps
- **[NEW]** `deleted_at` — soft delete (hard delete would orphan
  `hotspot_users`/`subscriptions` referencing this router)

## internet_packages
- id
- name
- price
- duration_value
- duration_unit: minutes, hours, days, weeks, months
- speed_limit
- data_limit nullable
- ~~mikrotik_profile~~ **[CHANGED]** — moved to a pivot table, see
  `router_package_profiles` below. A single global profile-name string
  breaks the moment two routers name their hotspot profiles differently.
- status: active, inactive
- timestamps

## **[NEW]** router_package_profiles
Pivot table mapping a package to the actual RouterOS profile name on each
specific router (profile names are configured per-router in RouterOS, not
globally).
- id
- router_id
- package_id
- profile_name
- timestamps
- unique(router_id, package_id)

## subscriptions
- id
- customer_id
- package_id
- router_id
- starts_at
- expires_at
- status: pending, active, expired, cancelled, suspended,
  **pending_activation** [NEW — see API_SPEC.md]
- amount
- timestamps
- **[NEW]** composite index on `(status, expires_at)` — the
  `subscriptions:expire` command scans this every minute; without the
  index this degrades as the table grows.

## payments
- id
- customer_id
- subscription_id nullable
- reference (unique, long random — see PaystackService::generateReference)
- amount
- currency default GHS
- channel nullable
- status: pending, successful, failed
- provider_response longtext nullable
- **[NEW]** `provider` varchar default 'paystack' — leaves room for another
  gateway later (e.g. direct MoMo) without a schema rework.
- paid_at nullable
- timestamps

## hotspot_users
- id
- customer_id
- router_id
- mikrotik_user_id nullable
- username
- password (hotspot-only secret, unrelated to customer account password)
- profile nullable
- disabled boolean default false
- timestamps

## vouchers
- id
- code (unique, long random — not sequential/short, see security note)
- package_id
- router_id nullable
- status: unused, used, expired
- used_by_customer_id nullable
- used_at nullable
- expires_at nullable
- timestamps
- **[NEW]** `batch_id` nullable — groups a bulk-generation run together for
  export/audit (Phase 11 wants "generate in quantity" + "export codes",
  which needs a way to group them).
- **[NEW]** `generated_by` (users.id) — who generated this batch.

## mikrotik_logs
- id
- router_id
- action
- request_payload longtext nullable — **sensitive fields redacted before
  write** (e.g. a hotspot user's password), see `MikrotikService::logAction()`
- response_payload longtext nullable
- status: success, failed
- error_message nullable
- timestamps

## activity_logs
- id
- user_id nullable
- customer_id nullable
- action
- description
- ip_address nullable
- timestamps

## system_settings
- id
- key (unique)
- value longtext nullable
- timestamps

Includes: business_name, business_phone, default_router_id,
paystack_public_key, sms_enabled, **grace_period_minutes** (see
SubscriptionService::isWithinGracePeriod — defines the grace period as
"expired for billing but MikroTik user not yet disabled"), auto_suspend_enabled.

**Phase 10 correctness note**: because `expireSubscription()` flips
`status` to `expired` immediately at `expires_at` (regardless of grace
period), a subscription past its grace-period cutoff is no longer matched
by the `subscriptions:expire` command's main `WHERE status = 'active'`
query. The command therefore runs a second sweep every minute over
still-enabled `hotspot_users`, checking each one's most recent subscription
against `isWithinGracePeriod()` independently — see
`app/Console/Commands/ExpireSubscriptions.php` for the full explanation.
This works at small-ISP scale without a schema change; if `hotspot_users`
grows very large, consider adding `hotspot_users.last_subscription_id` to
make it a single join instead of a per-row lookup.

---

# Royal WiFi Extension (Phase 2 of the extension build)

Adds a customer-facing MoMo voucher-purchase flow on top of the existing
system, for vouchers that are generated **on MikroTik itself** (not by
this app) and imported from an admin-uploaded PDF. Migrations
`2024_02_01_000001` through `2024_02_01_000006`.

## Key architectural decisions

**No new `packages` table.** `internet_packages` already has
name/price/duration/status — only `description` was missing, added via
migration 1. `duration_days` from the spec maps onto the existing
`duration_value` + `duration_unit` pair (set `duration_unit = 'days'` for
Royal WiFi packages). Reusing this table means the existing admin
Packages page, `router_package_profiles` mapping, and `InternetPackage`
model all keep working — Royal WiFi packages just live alongside the
original hotspot-subscription packages in the same table, distinguished
by which flow references them (`vouchers`/`orders` vs `subscriptions`).

**A new `orders` table, deliberately NOT a reuse of `payments`/
`subscriptions`.** The existing pair is shaped around Paystack + MikroTik
provisioning-on-activation. A Royal WiFi order never provisions
anything — it hands over a code that already exists on the router.
Bolting MoMo-specific columns onto the existing tables would mean every
row in both carries a pile of nullable fields that only make sense for
one of the two flows. `orders.reference` is unique by construction, which
structurally satisfies "a reference belongs to exactly one order" instead
of relying on application code to enforce it.

**A new `payment_sms_logs` table** — the actual evidence layer. The spec
is explicit that a customer-submitted reference alone is never enough to
mark an order paid; there must be a stored SMS (or an admin's manual
approval, logged in the existing `activity_logs`). This table is that
proof, matched or not — an unmatched/spam SMS still gets stored, not
discarded.

**A new `voucher_import_batches` table**, separate from the existing
`vouchers.batch_id` (a bare UUID, used only by the original in-app
"generate" flow). A PDF upload needs somewhere to hold the stored file
path, per-package extraction breakdown, and import history the spec asks
for — a raw UUID has nowhere to hang that. `vouchers.import_batch_id` (FK
to this new table) is used only for PDF-imported codes; a voucher is
never linked via both `batch_id` and `import_batch_id`.

## `vouchers` table — extended, not replaced

New columns (migration `2024_02_01_000003`):
- `import_batch_id` — FK to `voucher_import_batches`, nullable
- `order_id` — FK to `orders`, nullable (deferred FK added in migration
  `000005`, same pattern as the original `customers.current_subscription_id`)
- `assigned_to` / `assigned_at` — see below, distinct from `used_by_customer_id`/`used_at`
- `amount` / `duration_days` — price/duration **snapshotted** at
  import time, so editing a package later doesn't retroactively change
  what an already-sold voucher was sold as

**`status` enum widened**, data-preserving: old values were
`unused`/`used`/`expired`; new set is
`available`/`reserved`/`assigned`/`used`/`expired`/`invalid`. The
migration does a 3-step ALTER (widen → migrate existing `unused` rows to
`available` → narrow) specifically so this never errors or truncates
existing data — see the migration file for the exact SQL.

**Why `assigned_to`/`assigned_at` are separate from the existing
`used_by_customer_id`/`used_at`**: the original redemption flow
(`Customer\VoucherController::redeem`) treats redemption as immediate
full usage — the app provisions the MikroTik hotspot user right then, so
"redeemed" and "used" are the same instant, and `used_by_customer_id`/
`used_at` fire immediately as before (unchanged behavior for that flow).
A Royal WiFi voucher's MikroTik code already existed before the app ever
saw it, so "assigned to a customer via a completed order" and "actually
logged into the hotspot with it" are genuinely different moments — the
app can only be certain about the first without polling MikroTik for
usage (explicitly "optional future" work per the spec). `assigned_to`/
`assigned_at` are new fields, set only by the order-assignment path.

## `orders` table

- `id`, `customer_id`, `package_id`
- `amount` — price snapshot at order creation
- `reference` — unique, unpredictable (`Order::generateReference()`,
  format `RW-XXXXXX`)
- `status` — `pending` → `processing` → `verified` → `voucher_assigned`
  → `completed`, with `failed`/`expired`/`cancelled`/`manual_review` as
  the non-happy-path terminals. A real state machine, not a boolean.
- `voucher_id` — set only once a code is actually locked and assigned
- `verified_at`, `verification_method` (`sms_auto`/`manual_transaction_id`/`admin_manual`), `verified_by`
- `momo_transaction_id` — denormalized pointer to what was matched (the
  actual evidence lives in `payment_sms_logs`, this is for fast display)
- `expires_at` — pending orders don't hold potential voucher claims
  forever; an `orders:expire` scheduled command (Phase 4/5) mirrors the
  existing `subscriptions:expire` pattern
- Composite index on `(status, expires_at)` for that same reason the
  `subscriptions` table has one — the expiry sweep needs it cheap

## `payment_sms_logs` table

Raw storage for every SMS the forwarder relays, parsed fields nullable
(parsing can fail on an unexpected format — the raw SMS is still kept).
`matched_order_id` nullable — most rows may never match anything, and
that's fine, they're still evidence of what arrived.

## `voucher_import_batches` table

One row per PDF upload: stored file path, uploader, extraction totals
(`total_extracted`/`total_imported`/`total_duplicates`/`total_invalid`),
raw parser output in `extraction_meta` (so the preview screen can
re-render without re-parsing the PDF), and a `status` of
`pending_review` → `imported`/`cancelled`.

## What's NOT built yet (later phases of this extension)

- The PDF extraction service itself (Phase 3)
- SMS matching logic, order/voucher assignment transaction, email
  notifications (Phase 4/5)
- `orders:expire` scheduled command
- Frontend for any of this (Phase 6/7)
- `system_settings` will get two new keys (`momo_number`,
  `momo_account_name`) reusing the existing key-value settings
  infrastructure — no schema change needed, just new `SettingController`
  keys in Phase 3

---

## Extension Phase 4 additions

No new tables this phase (Phase 2 already covered `orders`,
`payment_sms_logs`, `voucher_import_batches`) — one new column and three
infrastructure fixes.

**`internet_packages.sales_channel`** (migration `2024_02_01_000009`,
enum `subscription`/`voucher`/`both`, default `subscription`) —
`internet_packages` is now shared by two customer-facing storefronts (the
original Paystack subscription flow and the new voucher-purchase flow).
Without this column, a Royal WiFi package would also show up in the old
"Buy Internet" listing and vice versa. The default preserves exact
existing behavior for every current row; nothing changes until an admin
explicitly marks a package `voucher` or `both`.

**Three genuine pre-existing gaps found and fixed**, not Royal WiFi
additions themselves but directly blocking this phase's email job (and,
it turns out, silently broken since much earlier phases):
- `config/queue.php` + `jobs`/`job_batches`/`failed_jobs` tables
  (migration `2024_02_01_000007`) — `QUEUE_CONNECTION=database` has been
  set since Phase 1 and `ActivateHotspotUserJob` dispatched since Phase 9,
  but nothing ever created what it dispatches *to*.
- `config/cache.php` + `cache`/`cache_locks` tables (migration
  `2024_02_01_000008`) — `CACHE_STORE=database` has been set since
  Phase 1, actively relied on by `SystemSetting::get()`/`set()` and the
  `login`/`voucher-redeem` rate limiters.
- `config/mail.php` — needed for the new voucher purchase email, but
  never existed at all before this phase (fully new, not a pre-existing
  bug — Phase 4 is the first thing in the whole project that sends mail).

See the README's Extension Phase 4 entry for the full reasoning on why
these were fixed now rather than left alone.

---

## Extension Phase 5 additions

**`voucher_import_batches.router_id`** (migration `2024_02_01_000010`,
nullable FK to `routers`) — a genuine gap found while wiring up MikroTik
status-checking: a PDF-imported voucher never captured which router it
was generated on, making it structurally impossible to know which
MikroTik device to query. An admin generates vouchers on MikroTik
per-router, and a single PDF export is almost always from one specific
router's batch, so this is captured once per import batch (optional
`router_id` field on upload) and propagated to every voucher row that
batch produces — `vouchers.router_id` was already a column from the
original project, just never populated by the import flow until now.
Nullable throughout: a batch can still be imported without selecting a
router, exactly as before — only the new status-sync feature requires it.

**No other schema changes.** MikroTik status-checking itself
(`VoucherUsageSyncService`) reuses the existing `MikrotikService` and
`routers` table entirely — reading `getHotspotUsers()` output, no new
tables needed to store what it finds beyond updating `vouchers.status`/
`used_by_customer_id`/`used_at` when RouterOS confirms genuine usage.
