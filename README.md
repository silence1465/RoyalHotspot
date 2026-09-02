# Hotspot Billing System

A MikroTik hotspot billing platform: customers connect, register, pick a
package, pay via Paystack, and get auto-provisioned on the router over
WireGuard. See `docs/PROJECT_OVERVIEW.md` for the full picture.

## Structure

```
hotspot-billing/
├── backend/     Laravel API
├── frontend/    React + Vite + Tailwind
├── docs/        Spec, schema, deployment docs
├── deploy/      Nginx configs, Supervisor config, backup script, crontab
└── database/    (migrations live inside backend/database — this folder
                  is for raw SQL dumps/seed data if needed)
```

## Backend setup

This skeleton was written without a local PHP/Composer environment
available, so the code hasn't been executed yet — run these steps on a
machine with PHP 8.2+ and Composer installed:

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
# edit .env: DB credentials, PAYSTACK_SECRET_KEY, PAYSTACK_PUBLIC_KEY
php artisan migrate --seed   # migrations/seeders added in Phase 2
php artisan serve
```

You'll also need to add the two Sanctum guards described in
`docs/API_SPEC.md` (`config/auth.php`) since admins and customers are
separate models, not a shared `role` column.

## Frontend setup

This one **was** run and verified in-sandbox (`npm run build` succeeds).

```bash
cd frontend
npm install
cp .env.example .env
npm run dev
```

Visit `http://localhost:5173` — you'll land on `/login` (customer) or
`/admin/login` (staff).

## Current status: Phase 2 complete

**Phase 1** — repo structure, Laravel skeleton (composer.json, .env.example,
versioned routes, service stubs, queued job), React skeleton (Vite +
Tailwind v4 + Axios + React Router, auth context, protected routes, working
login/register pages), and docs.

**Phase 2** — Database Design:
- [x] 13 migrations covering all 11 core tables plus the new
      `router_package_profiles` pivot and the deferred
      `customers.current_subscription_id` foreign key
- [x] 11 Eloquent models with relationships, casts (`encrypted` on
      `routers.api_password`, `hashed` on passwords), and hidden attributes
      on anything sensitive (router API password, hotspot user password,
      payment provider_response)
- [x] `config/auth.php` — dual Sanctum guards (`admin` / `customer`), since
      admins and customers are separate models; `routes/api.php` updated
      to use `auth:admin` / `auth:customer` instead of a placeholder role
      middleware
- [x] Factories: User, Customer, Router, InternetPackage
- [x] Seeders: default super admin (password from `DEFAULT_ADMIN_PASSWORD`
      env var, or a printed one-time random password if unset — never a
      hardcoded default), one sample router, the four sample packages from
      the original spec with a default `router_package_profiles` mapping

None of this has been run against a real database yet (no PHP/MySQL in
this sandbox) — run `php artisan migrate --seed` on a machine with those
installed to verify.

**Phase 3** — Authentication:
- [x] `bootstrap/app.php` + `bootstrap/providers.php` written (Laravel 11
      style) — needed to register the Sanctum ability middleware aliases
      and `AppServiceProvider`
- [x] **Important correction from Phase 2:** the two Sanctum guards
      (`admin`/`customer`) give convenient `$request->user('admin')` calls
      but Sanctum's guard doesn't actually filter by provider — it
      resolves purely from the token's polymorphic `tokenable` relation.
      Real separation now comes from **token abilities**: every token is
      issued with `['admin']` or `['customer']`, and routes enforce it via
      `abilities:admin` / `abilities:customer` middleware. See
      `docs/API_SPEC.md` for the full explanation.
- [x] `AdminAuthController` (login/logout/me) and `CustomerAuthController`
      (register/login/logout/me) — generic "credentials don't match"
      errors (no account enumeration), account status checks
      (deactivated admin / suspended customer blocked at login)
- [x] `RegisterCustomerRequest` — phone + username uniqueness validation
- [x] Login/register throttled at 6/min per IP (`AppServiceProvider`,
      `throttle:login`), separate from the general API rate limit
- [x] All auth actions write to `activity_logs`
- [x] Frontend: `/me` rehydration on page load so a refresh doesn't lose
      the logged-in user's data, `ProtectedRoute` now waits for that
      rehydration instead of redirecting on every reload

**Phase 4** — Admin Dashboard Layout:
- [x] `DashboardController@stats` — customer/router/subscription counts,
      today/month revenue (via `Payment::successful()` scope), recent
      payments + recent customers. Surfaces a `pending_activation_subscriptions`
      count so admins notice stuck MikroTik activations without digging.
- [x] Frontend shell: `Sidebar` (desktop fixed + mobile slide-over drawer),
      `Topbar` (mobile menu toggle, logout), `AdminLayout` wrapping all
      nested `/admin/*` routes
- [x] Reusable `StatCard`, `StatusBadge` (color-coded by status string,
      shared across every future admin list page), `SearchFilterInput`
- [x] Real dashboard page wired to the stats endpoint with a loading/error
      state — not just static markup
- [x] Page skeletons for Routers, Customers, Packages, Subscriptions,
      Payments, Vouchers, MikroTik Logs, Settings — each says which phase
      fills it in, so it's obvious what's still a placeholder
- [x] Verified in-sandbox: `npm run build` succeeds, dev server boots and
      serves `index.html` correctly

**Phase 5** — Router Management & MikroTik Service:
- [x] `RouterController` — full CRUD + `test-connection`, using the
      `MikrotikService` written back in Phase 1
- [x] `RouterRequest` — validates IPs, requires `api_password` on create
      but makes it optional on update (blank means "keep the current
      password" — the controller strips a blank value before saving so it
      never overwrites the real encrypted credential with an empty string)
- [x] Delete is blocked with a 422 if the router still has active
      subscriptions, rather than silently soft-deleting out from under
      live customers
- [x] Every create/update/delete writes to `activity_logs`
- [x] Frontend: real Routers page — searchable/debounced table, add/edit
      modal (`RouterFormModal`), delete confirmation (`ConfirmDialog`),
      inline "Test Connection" button that shows the live result next to
      each row and refreshes the router's online/offline status
- [x] Added a `.input` Tailwind v4 component class (`@layer components`
      + `@apply`) instead of repeating a long utility string on every form
      field — verified it actually compiles into the CSS output
- [x] Verified in-sandbox: `npm run build` succeeds (1870 modules)

**Phase 6** — Internet Package Management:
- [x] Admin `PackageController` — full CRUD, plus syncing the
      `router_package_profiles` mapping (full-replace semantics: routers
      removed from the form actually get their mapping deleted, not just
      ignored) inside a DB transaction alongside the package save
- [x] `PackageRequest` validates the package fields and, if a `profiles`
      array is submitted, that each entry references a real router
- [x] Customer-facing `GET /customer/packages` — active packages only,
      explicitly whitelisted fields (never leaks `router_package_profiles`
      / RouterOS internals to the storefront)
- [x] Delete blocked with a 422 if active subscriptions still reference
      the package, same pattern as router deletion in Phase 5
- [x] Frontend: real Packages page, add/edit modal with a dynamic
      per-router profile-mapping sub-form (add/remove rows, router
      dropdown excludes already-mapped routers), list view flags packages
      with **zero** router mappings in amber so an admin notices a package
      that looks live but can't actually activate anyone yet
- [x] Verified in-sandbox: `npm run build` succeeds (1871 modules)

**Phase 7** — Customer Portal:
- [x] `Customer\DashboardController` — current/pending subscription with
      package + router loaded, live remaining-time calculation, recent
      payments. Deliberately reveals the customer's own hotspot password
      via explicit `makeVisible('password')` — `HotspotUser.password` stays
      `$hidden` everywhere else (see `docs/API_SPEC.md`)
- [x] `Customer\SubscriptionController@show`, `Customer\PaymentController@index`
      (payment initialization itself is Phase 8), `Customer\ProfileController@update`
      (phone uniqueness re-validated excluding self; deliberately does NOT
      accept password changes through this endpoint — that deserves its
      own current-password-confirmation flow, not a general profile PUT)
- [x] Frontend: `CustomerLayout` — horizontal-scrolling top tabs rather
      than a sidebar, since this portal's primary audience is a phone
      browser on the captive portal, not a desktop session
- [x] Real Dashboard (subscription card, live `CountdownTimer`, masked
      hotspot password with a reveal toggle, recent payments), Subscription
      detail, Payment History, Profile (edit form), and Buy Internet
      (package browsing fully wired — checkout itself is Phase 8, so
      selecting a package shows what's coming rather than a dead button)
- [x] Verified in-sandbox: `npm run build` succeeds (1877 modules)

**Phase 8** — Paystack Payment Initialization:
- [x] `Customer\PaymentController@initialize` — creates the pending
      subscription + pending payment inside a short DB transaction, then
      calls Paystack's initialize endpoint **outside** that transaction
      (holding a DB transaction open across an external HTTP call is a
      real footgun — a slow/hanging Paystack request would tie up a
      connection for no reason)
- [x] `InitializePaymentRequest` validates that the chosen package
      actually has a `router_package_profiles` mapping for the chosen
      router — otherwise a customer could pay successfully and immediately
      land in `pending_activation` with no way to understand why
- [x] Customers without an email (the schema allows it — see Phase 2)
      get a synthesized placeholder address for Paystack, since Paystack
      requires one but the product doesn't
- [x] Temporary guard: rejects a purchase with 422 if the customer already
      has an active subscription on the requested router — flagged as a
      placeholder for the still-open renewal/stacking question, documented
      in `PROJECT_OVERVIEW.md`
- [x] `Customer\PackageController@index` now also returns each package's
      `available_routers` (id/name/location only — the RouterOS
      `profile_name` itself stays internal) so the buy flow can offer a
      valid location without a separate endpoint
- [x] Frontend: Buy Internet now actually selects a router and calls
      `/customer/payments/initialize`, then does a full-page redirect to
      Paystack's hosted checkout (`window.location.href`, not a fetch —
      the browser needs to actually navigate there)
- [x] `PaymentCallback` page (mapped to `PAYSTACK_CALLBACK_URL`) —
      deliberately does **not** call any verify/activate logic yet; it's a
      friendly waypoint only. Real activation is Phase 9's webhook, kept
      out of this phase on purpose to match the original phase boundary
- [x] Verified in-sandbox: `npm run build` succeeds (1878 modules)

**Phase 9** — Paystack Webhook & Auto Activation:
- [x] `WebhookController@paystack` — verifies signature against the raw
      body, ignores non-`charge.success` events with a 200 (avoids
      Paystack retry-storming us over events we don't act on), and is
      idempotent: checks `payment.status !== 'successful'` under
      `lockForUpdate()` before doing anything
- [x] Defense-in-depth: always calls `PaystackService::verifyTransaction()`
      directly rather than trusting the webhook payload's amount/status —
      and now actually **compares the verified amount against the
      expected amount**, logging critically and refusing to activate on a
      mismatch instead of just checking status
- [x] `SubscriptionService::activateSubscription()` extended to flip the
      customer's `status` to `active` and set `current_subscription_id` —
      this belongs in the activation logic itself so every activation path
      (webhook, admin retry, future voucher redemption) gets it for free
- [x] `Admin\SubscriptionController@retryActivation` — re-dispatches
      `ActivateHotspotUserJob` only; deliberately does NOT call
      `activateSubscription()` again, which would silently re-extend the
      customer's paid-for duration from `now()`
- [x] Everything here was mostly wiring — `PaystackService`,
      `SubscriptionService`, and `ActivateHotspotUserJob` were all written
      back in Phase 1 with this phase already in mind

**Phase 10** — Subscription Expiry and Suspension:
- [x] `subscriptions:expire` artisan command, scheduled every minute in
      `bootstrap/app.php` with `withoutOverlapping()` + `runInBackground()`
      (a slow run against an unreachable router shouldn't block or double
      up with the next minute's run)
- [x] **Found and fixed a real correctness gap from Phase 1**: since
      `expireSubscription()` flips status to `expired` immediately at
      `expires_at`, a subscription whose grace period hadn't yet elapsed
      would never be revisited to actually disable its MikroTik user once
      the grace period passed — it had already fallen out of the
      `status = 'active'` query. Fixed with a second sweep in the command
      that checks every still-enabled hotspot user's most recent
      subscription against `isWithinGracePeriod()` independently. Fully
      explained in `docs/DATABASE_SCHEMA.md` and the command itself.
- [x] Noted (in `docs/DEPLOYMENT_GUIDE.md`) that the scheduler is inert
      without a cron entry calling `php artisan schedule:run` every
      minute — easy to forget and silently means nothing ever expires
- [x] `Admin\SubscriptionController` filled out: `index` (filter by
      status/customer/router), `show`, `suspend` (preserves `expires_at`
      — this is a hold, not a cancellation), `activate` (un-suspend;
      explicitly refuses if the subscription expired while suspended,
      rather than silently re-enabling access nobody paid for), `cancel`
      (terminal)
- [x] Frontend: real Subscriptions page — status filter tabs, per-row
      action buttons that only show what's valid for that row's current
      status, confirmation dialog for the two consequential actions
      (suspend/cancel) but immediate execution for the low-stakes ones
      (reactivate/retry)
- [x] Verified in-sandbox: `npm run build` succeeds (1878 modules)

**Phase 11** — Voucher System:
- [x] `Admin\VoucherController` — bulk `generate` (each voucher gets a
      genuinely distinct random code, not merged/upserted; a shared
      `batch_id` UUID ties a print run together), `index` with
      status/batch/package filters, `destroy` blocked with a 422 for
      already-redeemed vouchers (deleting one would erase the audit trail
      linking a customer's activation back to how they got it)
- [x] `Customer\VoucherController@redeem` reuses
      `SubscriptionService::activateSubscription()` from Phase 1/9 rather
      than reimplementing activation — redemption gets the same
      idempotency, `starts_at`/`expires_at` calculation, customer
      status/`current_subscription_id` update, and queued MikroTik
      provisioning for free
- [x] Redemption creates a **zero-amount `provider: 'voucher'` payment
      row** rather than no payment record at all — keeps the customer's
      payment history and admin audit trail consistent, while
      `Payment::successful()`-based revenue reports (Phase 4's dashboard,
      Phase 12's reports) never get inflated by it
- [x] Router-less vouchers (redeemable at any router with the package
      mapped) respond with `requires_router_selection` + `available_routers`
      on the first attempt, so the frontend renders a location picker in a
      single round trip instead of a bare validation error
- [x] Same temporary "no double-active subscription per router" guard as
      the paid checkout flow (Phase 8) — same open question, same doc note
- [x] Frontend: real Vouchers admin page (status filter, generate modal,
      a batch-result modal with copy-all / CSV download for the freshly
      generated codes — client-side export, no extra backend endpoint
      needed since the codes are already in the generate response), and a
      new customer-facing Redeem Voucher page + nav tab
- [x] Verified in-sandbox: `npm run build` succeeds (1881 modules)

**Phase 12** — Reports, Logs, and Settings:
- [x] **Found and fixed a real gap dangling since Phase 4**: the admin
      Customers nav item existed from Phase 4 onward, but no backend
      endpoint was ever built behind it — Phase 7 only built the
      customer's own self-service controllers, not admin customer
      management. Added `Admin\CustomerController` (index/show) and the
      two missing routes.
- [x] `Admin\ReportController` — revenue (daily breakdown over a date
      range), payments, customers (with status counts), router-activity
      (aggregate counts from stored data only — deliberately does NOT make
      a live RouterOS call per router for the report, since that would
      make page load only as fast as the slowest/most unreachable router;
      use the Routers page's "Test Connection" for live status instead)
- [x] CSV export via `response()->streamDownload()` (doesn't buffer the
      whole file in memory — matters once a report covers thousands of
      rows) for both payments and customers
- [x] **Caught a real bug before it shipped**: the first draft of CSV
      export used `window.open()` to hit the authenticated endpoint
      directly — but Sanctum here is header-based Bearer token auth, not
      cookie-based, so a raw browser navigation can't attach the
      `Authorization` header and would have 401'd. Fixed by fetching as a
      blob through the authenticated axios instance and triggering the
      download client-side instead.
- [x] `Admin\LogController` — MikroTik logs (already-redacted at write
      time, safe to return as-is) and activity logs, both filterable
- [x] `Admin\SettingController` — explicit known-keys allowlist rather
      than accepting arbitrary key-value writes through the endpoint
- [x] Frontend: real Customers page (search/filter/status counts, CSV
      export, click-through detail modal with subscription + payment
      history), real Payments page (revenue summary + filterable table +
      CSV export), real Logs page (tabbed MikroTik/Activity, expandable
      rows for MikroTik request/response payloads), real Settings page
- [x] Verified in-sandbox: `npm run build` succeeds (1881 modules)

**Phase 13** — Deployment to VPS:
- [x] **Finally able to syntax-check the whole backend**: installed
      `php-cli` via `apt` (Packagist itself is still unreachable in this
      sandbox, so `composer install` remains untested, but every one of
      the 69 backend PHP files now passes `php -l` with zero syntax
      errors — real verification that wasn't possible in any earlier
      phase)
- [x] Full `docs/DEPLOYMENT_GUIDE.md` — base packages, MySQL setup,
      backend clone/configure/migrate, Supervisor queue worker, cron
      scheduler, Nginx, Certbot SSL, frontend build, and an end-to-end
      verification checklist that specifically exercises the MikroTik
      integration (add router → test connection → generate voucher →
      redeem → confirm hotspot user actually got created)
- [x] `docs/WIREGUARD_SETUP.md` — VPS-as-server / MikroTik-as-peer tunnel
      config on both sides, including the `persistent-keepalive` setting
      that's easy to miss and causes the tunnel to silently drop for any
      router behind NAT (i.e. most of them)
- [x] `docs/PRODUCTION_SECURITY.md` — the RouterOS **API-SSL certificate**
      setup that the original spec treated as a config toggle but is
      actually a real step (missing it fails silently — "Test Connection"
      just shows a generic error with no hint the certificate is the
      problem), restricting the API to the WireGuard interface only,
      dedicated least-privilege RouterOS API user, VPS firewall rules,
      `APP_KEY` backup guidance (losing it means losing the ability to
      decrypt every stored router password), and backup verification
- [x] Real deployable configs in `deploy/`, not just prose: two Nginx
      site configs (API + frontend, both HTTP→HTTPS redirect,
      React-Router-aware fallback on the frontend one), a Supervisor
      config for the queue worker, a `mysqldump`-based backup script
      (syntax-checked with `bash -n`) with rotation, and the two cron
      entries the whole system depends on (scheduler + backup)
- [x] Verified in-sandbox: `npm run build` succeeds (1881 modules),
      `bash -n` passes on the backup script

## All 13 phases complete

Everything from the original spec is now built. What's still genuinely
open, flagged honestly rather than silently left broken:
- The subscription renewal/stacking question (`docs/PROJECT_OVERVIEW.md`)
  — only has a temporary "reject if already active" guard, not a real
  product decision
- `composer install` itself has never actually been run — this sandbox
  can reach `apt`/`npm`/GitHub but not Packagist, so dependency
  resolution for the PHP side is unverified beyond syntax-checking
- SMS integration — explicitly "optional future" in the original spec,
  never in scope

---

# Royal WiFi Extension

Adds a customer-facing MoMo voucher-purchase flow on top of everything
above, for vouchers generated on MikroTik itself and imported from an
admin-uploaded PDF (rather than generated by this app). Being built the
same incremental, phase-by-phase way as the original 13 phases.

**Extension Phase 1 — Inspection**: complete (see conversation). Key
finding: the existing voucher system generates codes in-app and
provisions a new MikroTik hotspot user on redemption; Royal WiFi vouchers
are the reverse — the code already exists on MikroTik, so redemption
means "assign an already-real code," not "provision a new one." This is
the central fork the rest of the extension is built around.

**Extension Phase 2 — Database**: complete.
- [x] Migrations `2024_02_01_000001` through `000006` — all additive,
      zero destructive changes to existing tables/data (the one enum
      widening on `vouchers.status` is done as a 3-step ALTER that
      migrates existing rows rather than risking truncation — see
      `docs/DATABASE_SCHEMA.md`)
- [x] Reused `internet_packages` as the spec's "packages" table (added
      only a `description` column) instead of creating a duplicate
- [x] New `orders` table — deliberately separate from `payments`/
      `subscriptions`, with the reasoning written up in the schema doc
- [x] New `payment_sms_logs` table — the actual evidence layer for
      payment verification, not just a text-matching shortcut
- [x] New `voucher_import_batches` table — gives PDF uploads a real,
      queryable history instead of a bare UUID
- [x] Extended `Voucher`, `InternetPackage`, `Customer`, `User` models in
      place; no duplicate models created
- [x] Verified in-sandbox: `php -l` passes on all 6 new migrations and
      all touched/new models

Next: **Extension Phase 3 — Backend: PDF import** (upload endpoint, text
extraction, voucher code/package detection, duplicate/malformed
detection, preview-before-import).

**Extension Phase 3 — Backend: PDF import**: complete.
- [x] **Found and fixed a real pre-existing gap**: `config/filesystems.php`
      never existed in this project — nobody had used `Storage::` yet, so
      it silently worked by omission until now. Added it, with the
      `local` disk pointed at a private (never web-served) path, since
      voucher PDFs and the codes inside them are sensitive.
- [x] `VoucherPdfExtractionService` — native text extraction
      (`smalot/pdfparser`) first, with an optional OCR fallback
      (`pdftoppm` + `tesseract` CLI tools) that only fires if extraction
      looks suspiciously thin, checks both binaries actually exist before
      attempting anything, and never hard-fails — a missing OCR toolchain
      just means a thinner extraction the admin can correct in preview
- [x] Package/duration detection matches by **price** against
      `internet_packages` (the most reliable signal from a PDF) — a
      section the parser can't match gets flagged, not guessed, and the
      admin resolves it explicitly via `package_overrides` at confirm time
- [x] Every extraction constant (code regex, package-header regex, size
      limits, OCR trigger threshold) lives in `config/voucherimport.php`,
      not hardcoded — real MikroTik PDF export formats will likely need
      tuning without a code change
- [x] `Admin\VoucherImportController` — upload creates a `pending_review`
      batch and returns the preview but creates **zero** voucher rows
      (mandatory preview step per spec); `confirm()` re-checks duplicates
      **live** against the database rather than trusting the upload-time
      snapshot, and locks the batch row (`lockForUpdate`) so a
      double-clicked Confirm button can't double-import the same batch
- [x] Fixed a real bug caught before it shipped: an early draft of the
      section-parsing loop used PHP array-reference juggling
      (`&$currentSection`) to track the "current" section while iterating
      — a genuinely fragile pattern that's easy to get subtly wrong.
      Replaced with a plain index-based approach before it ever ran.
- [x] Verified in-sandbox: `php -l` passes on every new/modified file,
      `composer.json` re-validated as well-formed JSON after adding
      `smalot/pdfparser`

Next: **Extension Phase 4 — Backend: orders, MoMo payment page data,
SMS matching, voucher assignment** (the core purchase flow — order
creation, the pending-payment page's backend, SMS Forwarder webhook,
payment matching engine, and the transactional voucher-locking assignment
this whole extension has been building toward).

**Extension Phase 4 — Backend: orders, MoMo, SMS matching, assignment**:
complete.

- [x] **Found and fixed three real pre-existing infrastructure gaps**,
      not new-feature work: `config/queue.php` + the `jobs`/`job_batches`/
      `failed_jobs` tables never existed despite `QUEUE_CONNECTION=database`
      being set since Phase 1 and `ActivateHotspotUserJob` (core MikroTik
      provisioning) being dispatched since Phase 9 — every queued job in
      the entire project has had nowhere to actually go. Same story for
      `config/cache.php` + the `cache`/`cache_locks` tables, actively
      relied on by `SystemSetting::get()` and the rate limiters since
      Phase 1. Fixed both since Phase 4's email job would hit the
      identical problem, and both directly affect existing MikroTik/
      payment operation per your instructions on when to fix pre-existing
      bugs. `config/mail.php` was also missing, but that one's just new
      (nothing sent mail before this phase).
- [x] Added `internet_packages.sales_channel` — `internet_packages` is
      now shared by two different storefronts (original subscription
      flow, new voucher flow) and without this a Royal WiFi package would
      leak into the old "Buy Internet" listing and vice versa. Default
      preserves exact existing behavior for every current row.
- [x] `VoucherAssignmentService` — the transactional core every
      verification path (SMS auto-match, manual "Already Paid", admin
      approval, PDF-import backfill) funnels through. `lockForUpdate()`
      on both the order and the candidate voucher row is what makes "a
      voucher must never be assigned to two customers" actually true
      under concurrency, not just true in the happy path.
- [x] Idempotent: calling `verifyAndAssign()` twice for the same order
      (e.g. a duplicate SMS) is a safe no-op, not a double-assignment
- [x] "No voucher available" never loses the payment — order stays
      `verified` with `voucher_id = null` (the "waiting for inventory"
      state), logged for admin visibility, and picked back up
      automatically the moment a matching PDF import lands (backfill
      added to the existing `VoucherImportController::confirm()`, run
      **outside** the import transaction so one slow order can't hold up
      the whole import)
- [x] `PaymentMatchingService` — reference-based auto-match only for
      unattended inbound SMS (deliberately no fuzzy amount/timestamp
      fallback with no human in the loop); broader transaction-ID-or-
      reference search for the customer-initiated "Already Paid" form,
      which already has a human involved. Amount comparison uses
      `bccomp()`, never float `==`. A reference match with a mismatched
      amount lands in `manual_review`, not silently ignored or silently
      trusted.
- [x] `SendVoucherPurchaseEmailJob` — dispatched only after the
      assignment transaction has already committed, so it structurally
      cannot roll anything back; logs both success and failure to
      `activity_logs` either way, mirroring `ActivateHotspotUserJob`'s
      existing tries/backoff/`failed()` pattern
- [x] `VerifySmsForwarderToken` — shared-secret auth via `hash_equals()`
      (timing-safe), fails closed if the token isn't configured at all
      rather than silently accepting every request
- [x] Extended (not duplicated): `Customer\VoucherController` gained
      `myVouchers()`, `Admin\SettingController` gained the three new
      settings keys, `AppServiceProvider` gained two new rate limiters,
      `Customer\PackageController` gained one line filtering to the
      subscription channel
- [x] Verified in-sandbox: `php -l` passes on all 24 new/modified PHP
      files this phase

Next: **Extension Phase 5 — Backend: admin manual approve/reject UI data
finalization and any remaining polish**, then **Extension Phase 6/7 —
Frontend** (customer buy/pay/verify/my-vouchers flow, admin voucher
import UI, payment management, SMS logs).

**Extension Phase 5 — Existing MikroTik Integration**: complete.

A note on phase numbering: admin manual approve/reject and the email job
(originally planned for this slot) landed inside Extension Phase 4
instead, since they're inseparable from the assignment logic itself. This
phase instead maps to the **original spec's own "PHASE 5 — EXISTING
MIKROTIK INTEGRATION"** — connecting the new voucher system to the
existing MikroTik functionality without breaking it.

- [x] **Audited first, before writing anything**: confirmed zero calls
      to `MikrotikService` anywhere in `VoucherAssignmentService`,
      `PaymentMatchingService`, or either new `OrderController` — the
      order/payment/assignment flow genuinely never touches MikroTik at
      all, by design (Royal WiFi vouchers are pre-existing MikroTik codes,
      not app-provisioned ones). Confirmed the same for `hotspot_users`
      (the original flow's table) — Royal WiFi writes only to `vouchers`,
      never there. Existing hotspot login/subscription functionality is
      untouched by every phase of this extension so far.
- [x] **Found and fixed a real gap this phase's own goal exposed**:
      PDF-imported vouchers never captured which router they came from,
      making MikroTik status-checking structurally impossible. Added
      `voucher_import_batches.router_id` (optional, propagated to every
      voucher the batch produces) — necessary plumbing for this phase's
      actual goal, not scope creep.
- [x] `VoucherUsageSyncService` — checks a voucher's live status against
      MikroTik by reusing the existing `MikrotikService::getHotspotUsers()`
      wholesale, no second connection mechanism. Matches by code-as-
      username, parses RouterOS's compact uptime format, and only writes
      back to the voucher row when genuine usage is confirmed (never
      speculatively).
- [x] On-demand single-voucher check
      (`POST /admin/vouchers/{id}/check-mikrotik-status`) works
      regardless of the sync toggle — the deliberately safe way to
      validate the code-equals-RouterOS-username assumption against one
      real voucher before trusting it in bulk
- [x] `vouchers:sync-mikrotik-status` — bulk version, opt-in via
      `VOUCHER_MIKROTIK_SYNC_ENABLED` (default false) and **deliberately
      not added to the scheduler**, since it makes a live RouterOS call
      per router and shouldn't run unattended until the matching
      assumption is confirmed against a real deployment
- [x] Verified in-sandbox: `php -l` passes on all 9 new/modified files

Next: **Extension Phase 6 — Frontend: customer buy/pay/verify/my-vouchers
flow** (the customer-facing half of everything built in Phases 2–5).

**Extension Phase 6 — Frontend: customer voucher flow**: complete.

- [x] **Found and fixed a backend gap while building this**: there was no
      `GET /customer/orders` list endpoint — only per-reference lookups
      existed. The My Vouchers/Payment History page genuinely can't work
      without one, so added `Customer\OrderController::index()` before
      touching any frontend code.
- [x] `BuyVoucher.jsx` (`/vouchers/buy`) — the "Welcome to Royal WiFi"
      package listing from the spec's own example
- [x] `PaymentPage.jsx` (`/payment/:reference`) — the whole payment
      lifecycle in one page: MoMo instructions, live 5-second status
      polling that stops itself at a terminal status, the "I have made
      payment" acknowledgement, the "Already Paid" fallback form, and the
      voucher success view — which is structurally only reachable once
      `has_voucher` is actually true, never earlier, matching the spec's
      "do not display the voucher before successful payment verification"
- [x] Verified the one real routing risk before trusting it: `/payment/:reference`
      and the existing `/payment/callback` (Paystack) could theoretically
      collide since `:reference` could match the literal string
      "callback". React Router v6 ranks static path segments over dynamic
      ones regardless of declaration order, so this resolves correctly —
      confirmed against the library's routing behavior, not just assumed.
- [x] `MyVouchers.jsx` (`/my-vouchers`) — tabbed Vouchers/Payment History,
      reusing the exact tab pattern already established in `AdminLogs.jsx`
      rather than inventing a new one
- [x] Extended (not duplicated) the shared `StatusBadge` component with
      every new order/voucher status color (`verified`, `voucher_assigned`,
      `manual_review`, `processing`, etc.) — every page that renders a
      status gets consistent colors for free, not just Payment Page
- [x] Added a compact Royal WiFi teaser banner to the existing
      subscription-focused dashboard rather than duplicating the full
      package-listing UI there too — the dedicated pages own the real
      experience, this is just a discoverability hook
- [x] New `CopyButton` component (voucher code copy-to-clipboard),
      reused across the payment success view and My Vouchers list
- [x] Verified in-sandbox: `npm run build` succeeds (1885 modules) —
      note the sandbox reset since the last phase, so `npm install` had
      to be re-run from scratch before this build actually worked

Next: **Extension Phase 7 — Frontend: admin voucher import UI, payment
management, SMS logs** (the admin-facing half — PDF upload/preview,
orders list with approve/reject, SMS log viewer).

**Extension Phase 7 — Frontend: admin voucher import UI, payment
management, SMS logs**: complete.

- [x] **Found and closed two backend gaps before the frontend could work
      at all**: no inventory-breakdown endpoint (`GET /admin/vouchers/inventory`,
      with `low_stock_threshold` as a real editable setting rather than
      hardcoded) and no SMS log listing endpoint (`GET /admin/sms-logs`)
      existed yet — both genuinely required by this phase's own scope,
      not scope creep
- [x] Extended (not duplicated) the existing `Admin\DashboardController`
      with a nested `royal_wifi` stats block — sales totals, order status
      counts, low-stock count, customers-with-vouchers, recent
      orders/imports — kept structurally separate so the original
      dashboard payload is unchanged for anything already reading it
- [x] **Voucher import UI**: `AdminVouchers.jsx` gained a Codes/Import PDF
      tab split plus inventory summary cards. The import preview modal is
      the real "Edit Vouchers" capability the spec insists on — per-code
      checkboxes to exclude entries, per-section package resolution when
      auto-matching by price fails, and a caught bug before it shipped:
      the frontend was about to send `null` for an unset package
      override, which would have failed the backend's `integer`
      validation rule — fixed to omit the key entirely instead
- [x] **Payment management UI**: `AdminPayments.jsx` gained a Paystack
      Payments/Voucher Orders tab split. `OrderDetailModal` shows the
      actual matched SMS evidence alongside the order (not just a status
      badge), and approve/reject/cancel/retry-voucher all route through
      the same backend endpoints built in Phase 4 — no duplicate
      approval logic on the frontend
- [x] **SMS log viewer**: added as a third tab on the existing
      `AdminLogs.jsx`, following the exact same expandable-row pattern
      already used for MikroTik logs, rather than inventing a new one
- [x] `AdminSettings.jsx` — MoMo number/account name, order expiry, and
      low-stock threshold, all wired to the settings extended in Phase 4/7
- [x] Verified in-sandbox: `npm run build` succeeds (1891 modules), and
      given the volume of backend files touched this phase, ran a full
      `php -l` sweep across every file in `app/`, `config/`, and
      `routes/` — zero errors

## Royal WiFi extension: functionally complete

Every piece from the original mega-spec now exists: inspection, database,
PDF import, orders/SMS/payment matching/voucher assignment, MikroTik
integration, and both customer and admin frontends. What's left is
**Extension Phase 8 — final wire-check and test pass** (the original
spec's own testing checklist — register, buy, pay, SMS match, voucher
display, copy, email, duplicate/edge cases, existing MikroTik login still
working, mobile responsiveness) — worth doing as its own deliberate pass
rather than assuming everything built across 7 phases integrates
perfectly untested.

---

# Extension Phase 8 — Test Pass

Ran the original spec's own 25-item testing checklist as a **static code
trace** — reading the actual current source for every scenario and
verifying the logic genuinely handles it, rather than assuming 7 phases
of work integrate correctly untested. This sandbox still can't run the
full stack live (no Packagist access, so `composer install` — and
therefore `php artisan serve`/`migrate`/queue worker — has never
actually executed), but static tracing this thoroughly found two real
bugs, which is exactly the point of doing it deliberately instead of
skipping straight to "looks done."

## Two real bugs found and fixed

**1. The original in-app voucher generation feature was completely
broken.** `Admin\VoucherController::generate()` still wrote
`status => 'unused'` — but Phase 2's enum-widening migration
(`2024_02_01_000003`) renamed `'unused'` to `'available'` and narrowed
the final enum to no longer include the old value at all. Every call to
`POST /admin/vouchers/generate` would have failed with a database error.
This wasn't Royal WiFi code — it was a pre-existing feature broken by a
Royal WiFi migration that never got cross-checked against every call
site using the old vocabulary. Fixed: `'unused'` → `'available'`.

**2. A frontend dead-end for `manual_review` orders.** The "Already
Paid" fallback form stayed visible on the payment page for orders in
`manual_review`, but `Customer\OrderController::verify()` only actually
attempts a new match for `pending`/`processing` orders — for anything
else it just returns a "this order is already X" message without calling
the matcher. A customer in manual_review could fill out the form and get
a response implying nothing happened, with no real path forward. Fixed:
the form now hides for `manual_review` the same way it already hid for
the waiting-for-inventory case.

## Two testability gaps closed

Neither is a bug, but without them **nothing in the checklist below is
actually runnable** on a fresh install:

- **No Royal WiFi packages existed anywhere.** `internet_packages` had
  four router-subscription packages (default `sales_channel='subscription'`)
  but zero `voucher`-channel ones — `GET /customer/voucher-packages`
  would return an empty list forever until an admin manually created
  some. Added `RoyalWifiPackageSeeder` — Weekly (GHS 30/7 days) and
  Monthly (GHS 100/30 days), matching the spec's own example numbers
  exactly.
- **No vouchers existed to assign.** Added `RoyalWifiVoucherSeeder` —
  five `available` test vouchers using the exact example codes from the
  spec's own sample PDF (`RW30-82KD-91PL` etc.), so the purchase flow can
  actually be exercised without needing a real MikroTik PDF export first.
  Clearly commented as test/demo data to replace via real PDF import
  before going live.

## Checklist trace results

| # | Scenario | Result |
|---|----------|--------|
| 1–2 | Register / Login | Pre-existing, untouched by this extension — unaffected |
| 3, 12 | Buy GHS 30 / GHS 100 | Now testable end-to-end with the seeded packages above |
| 4 | Reference generation | `Order::generateReference()` — unique-checked loop, `RW-XXXXXX` format |
| 5 | Payment pending | Confirmed: order created `pending`, payment page renders instructions |
| 6–7 | SMS received & matched | Confirmed: webhook → `SmsBodyParser` → `PaymentMatchingService::processIncomingSms()` |
| 8–9 | Voucher assigned & displayed | Confirmed: `has_voucher` only ever becomes true after real assignment — no code path can show a voucher earlier |
| 10 | Copy voucher | `CopyButton` — standard Clipboard API, needs HTTPS or localhost in production (already covered by the existing deployment docs) |
| 11 | Email sent | Confirmed reachable — **does require** the queue worker actually running (`php artisan queue:work`) and `MAIL_MAILER` configured; both already documented |
| 13–14 | Wrong transaction ID / reference | Confirmed: no match found → order stays `pending`, generic message, no other customer's data exposed |
| 15 | Duplicate transaction | Confirmed: a transaction ID already claimed by another SMS log is rejected before matching runs |
| 16 | Duplicate voucher assignment | Traced the actual concurrency behavior, not just assumed it: `lockForUpdate()` on the voucher row means a second concurrent request blocks until the first transaction commits, then re-evaluates its `WHERE status = 'available'` and correctly no longer sees the now-`assigned` row — this is standard SELECT-FOR-UPDATE semantics, confirmed correct by design |
| 17 | No available voucher | Confirmed: order stays `verified`, `voucher_id` null, logged for admin, picked up automatically by the next matching PDF import |
| 18–20 | PDF upload / extraction / duplicate detection | Confirmed built in Phase 3, live re-check at confirm time (not just the upload-time preview snapshot) |
| 21–22 | Admin approve / reject | Confirmed both write to `activity_logs`, per the spec's own requirement |
| 23–24 | Existing MikroTik login/connection | Re-confirmed `app/Services/MikrotikService.php` has never been modified at any point in this entire extension (checked directly, not assumed) |
| 25 | Mobile responsiveness | New customer pages follow the same mobile-first patterns as the pre-existing customer portal; admin pages follow the same desktop-oriented patterns as the pre-existing admin panel — consistent with, not divergent from, existing conventions |

## What's still genuinely unverified

Being honest about the limits of a static trace: **this has never been
run against a live PHP process, a real MySQL database, or an actual
MikroTik router.** `composer install` has never executed in this
sandbox. The regex patterns for PDF extraction and SMS parsing are
best-effort against the spec's examples, not tuned against real MikroTik
export formats or real MoMo provider SMS text — expect to adjust
`config/voucherimport.php` and `config/smspayment.php` once you test
against your actual data, exactly as flagged when those were built.

Verified in-sandbox this phase: full `php -l` sweep across every backend
file including the new seeders (zero errors), `npm run build` succeeds
(1891 modules).
