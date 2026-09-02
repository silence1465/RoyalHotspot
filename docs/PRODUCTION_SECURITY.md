# Production Security Checklist

## RouterOS API-SSL certificate

The original spec says "use API-SSL 8729" like it's a config toggle — it
isn't. RouterOS needs an actual TLS certificate installed and assigned to
the API-SSL service before it will accept a connection at all, and this
fails silently: `MikrotikService::testConnection()` just returns a
generic connection error with no hint that the real problem is a missing
certificate.

On the router (self-signed is fine, since the connection is already
inside a WireGuard tunnel — this cert is defense-in-depth, not the
primary security boundary):

```
/certificate
add name=api-cert common-name=mikrotik-api days-valid=3650 key-usage=tls-server
sign api-cert

/ip service
set api-ssl certificate=api-cert disabled=no port=8729

/ip service
set api disabled=yes
```

That last line is the one most guides skip — **explicitly disable the
plain (non-SSL) API service on port 8728**. Leaving it enabled means an
unencrypted RouterOS API is sitting there even if nothing in this app
ever connects to it deliberately.

## Restrict the API to the WireGuard interface only

Beyond disabling plain API, restrict API-SSL itself to only accept
connections from the WireGuard interface — so even if something on the
router's LAN side got compromised, it couldn't reach the RouterOS API
either:

```
/ip service
set api-ssl address=10.10.0.1/32
```

(`10.10.0.1` is the VPS's WireGuard address from `WIREGUARD_SETUP.md` —
only that single IP, inside the tunnel, is allowed to speak to the API.)

## Router credentials

- `routers.api_password` is stored via Laravel's `encrypted` cast (see
  `app/Models/Router.php`) — encrypted at rest using `APP_KEY`. **This
  means losing `APP_KEY` means losing the ability to decrypt every stored
  router password.** Back up `APP_KEY` somewhere separate from the
  database backup (a password manager, not another file in the same
  server that gets backed up to the same place).
- Never log the decrypted password. `MikrotikService::logAction()`
  already redacts specific fields (like a hotspot user's password) before
  writing to `mikrotik_logs` — if you add new MikroTik actions that take
  a password parameter, add it to the `$redact` list too.
- Use a dedicated RouterOS API user with the minimum required
  permissions, not the router's main admin account:

```
/user group
add name=api-only policy=api,read,write,!local,!telnet,!ssh,!ftp,!reboot,!password,!policy,!test,!winbox,!web,!sniff,!sensitive,!romon

/user
add name=api-admin group=api-only password=<strong-random-password>
```

## VPS firewall

Minimum open ports:

```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 22/tcp        # SSH — consider restricting to your IP
sudo ufw allow 80/tcp        # HTTP (redirects to HTTPS + ACME challenge)
sudo ufw allow 443/tcp       # HTTPS
sudo ufw allow 51820/udp     # WireGuard
sudo ufw enable
```

Nothing else needs to be open — MySQL should only listen on `127.0.0.1`
(default), and the RouterOS API is reachable only through the WireGuard
tunnel, never through a port opened on the VPS's public interface.

## Application-level

- `APP_DEBUG=false` in production — with it `true`, an unhandled
  exception dumps a full stack trace (including `.env` values in some
  cases) to whoever triggered it.
- HTTPS only — both Nginx configs in `deploy/` redirect HTTP to HTTPS;
  don't remove that redirect.
- `PAYSTACK_SECRET_KEY` and `APP_KEY` are the two most sensitive values
  in `.env` — restrict file permissions (`chmod 600 .env`) and make sure
  `.env` is in `.gitignore` (verify before your first commit — it's easy
  to accidentally commit a working `.env` while testing locally).

## Backups

`deploy/backup-database.sh` + `deploy/crontab.txt` set up a daily
`mysqldump` with 14-day local rotation — see `DEPLOYMENT_GUIDE.md` step 5.
Two things worth doing beyond what the script does automatically:

1. **Verify backups are actually happening**, not just that the cron
   entry exists — check `/var/backups/hotspot-billing/` for a file dated
   today, a week after setup, not just right after you configure it.
2. **Sync backups off-server.** The script only handles local rotation;
   if the VPS itself is lost or compromised, local backups are lost with
   it. Add an `rclone`/`aws s3 sync`/similar step to the script pointed at
   off-server storage — deliberately left out of the script itself since
   it needs credentials specific to whichever provider you choose.

## Ongoing

- Keep RouterOS itself updated — this app is built assuming a
  reasonably current RouterOS v7.x; very old versions may have API
  behavior differences.
- `certbot.timer` handles SSL renewal automatically, but confirm it's
  actually enabled (`systemctl status certbot.timer`) rather than
  assuming — a silently-failed renewal means an expired cert three months
  from now with no warning until it happens.
