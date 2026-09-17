# Release readiness

Status: **application capacity controls implemented; live per-ISP RouterOS routing still requires topology validation**.

## Corrections in this working tree

- Paid live packages remain unstarted until a RouterOS-confirmed connection.
- Repeated Connect requests reuse a pending session on the same device.
- Confirmation revalidates the purchase; expired access cannot be confirmed active.
- Connection preparation, confirmation, current-state reconciliation and expiry use the same customer/router cache lock.
- Expiry retries retain eligible records after RouterOS failure and return a failing command exit code.
- RouterOS revocation validates intermediate responses, resolves current account IDs by username, and only removes the target user's sessions/cookies.
- Expiring an old purchase does not disable a newer started purchase.
- Disabled administrator tokens are rejected; global settings require a super administrator.
- Search, package mappings, voucher lists/inventory, router/SMS logs and campaign lists honor router scope. Restricted admins receive no global activity feed.
- Shared packages cannot be modified by administrators who lack access to any mapped router.
- Navbar mode load/save failures are visible. Unsupported modes cannot be enabled through the API.
- RouterOS version and repeatable per-router ISP uplinks can be maintained from Add/Edit Router. Each ISP stores its WAN interface, gateway, routing table, monthly capacity, subscriber limit, priority and enabled state.
- Data Cap reserves the entire package allowance only after payment verification and releases availability when the purchase ends.
- User + Data Cap also enforces per-ISP subscriber slots and assigns new customers by ISP priority while retaining a customer's previous ISP when possible.
- Purchases that lose a capacity race are queued with zero reservation and retried oldest-first every minute.
- Used balances can roll into the immediately following month only, and the rollover is charged against that month's ISP capacity.
- Customer package selection reports capacity availability before payment; admin ISP records report reserved and remaining bytes and subscriber slots.
- Administrators can independently enable automatic ISP failover and automatic return-to-preferred-ISP behavior per router; ISP priority determines the fallback order.

## Required work before live per-ISP routing release

The application allocation engine is present. These items require the real router topology or further domain decisions:

- Apply each stored ISP assignment to RouterOS policy routing without breaking HotSpot authentication.
- Validate per-ISP traffic attribution against the router's actual interface counters and routing configuration.
- Decide whether partial rollover should be disclosed differently when the next month's ISP capacity cannot cover the full unused balance.
- Complete route-by-route authorization audit, including legacy/global voucher imports and complaints that do not currently carry a router identity.

Normal, Data Cap, and User + Data Cap modes are selectable. ISP assignment currently controls application admission and accounting; it does not by itself alter RouterOS packet routing.

## MikroTik prerequisites

Obtain RouterOS version, WAN interface names, uplink gateways, HotSpot authentication methods, address pools, routing rules and tables. Do not include passwords or private keys.

The RouterOS HotSpot documentation states default-routing-table limitations and cautions against PCC with HotSpot. The earlier proposed generic multiple-table setup is not a validated production recipe:
https://manual.mikrotik.com/docs/authentication-authorization-accounting/hotspot-captive-portal/

Validate routing on a test router with the actual topology before enabling per-ISP traffic accounting. Verify that direct credential, cookie, MAC-cookie and MAC authentication cannot bypass purchase eligibility. Automated mocks do not establish this.

## Deployment verification

- Back up the database and router configuration and verify restoration separately.
- Use production configuration with debugging off, HTTPS, shared persistent cache locks and a supervised asynchronous queue worker.
- Run the scheduler every minute, retain scheduler errors, and alert on failed expiry commands and failed jobs.
- Restart workers after deploying code. Verify router API permissions allow user disable, active-session removal and cookie removal.
- Test a short-duration purchase: payment, automatic Connect, router confirmation, unchanged expiry on reconnect, data accounting, expiry disconnect and denial of reauthentication.
- Repeat with router outage at expiry, then recovery, and with a replacement purchase. A stale old request must not disconnect the new purchase.
- Test actual MySQL concurrent payments/Connect requests; SQLite unit tests do not prove row-lock behavior under production concurrency.

No production database, router or server was changed by local regression tests.
