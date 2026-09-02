# WireGuard Setup

The VPS is the WireGuard **server**; each MikroTik router is a **peer**
that dials in. Laravel talks to `routers.wireguard_ip` — the router's
tunnel IP, never its public internet IP — so the RouterOS API is never
exposed to the open internet at all, only reachable through the tunnel.

## 1. VPS side (WireGuard server)

```bash
sudo apt install -y wireguard
cd /etc/wireguard
umask 077
wg genkey | tee server_private.key | wg pubkey > server_public.key
```

Create `/etc/wireguard/wg0.conf`:

```ini
[Interface]
Address = 10.10.0.1/24
ListenPort = 51820
PrivateKey = <contents of server_private.key>
SaveConfig = true

# One [Peer] block per MikroTik router — added as routers.wireguard_ip
# gets assigned to each one (see the RouterSeeder pattern in Phase 2,
# and the actual "add a router" admin UI from Phase 5).
[Peer]
PublicKey = <router's public key, from step 2>
AllowedIPs = 10.10.0.2/32
```

Enable IP forwarding and start the interface:

```bash
echo 'net.ipv4.ip_forward = 1' | sudo tee -a /etc/sysctl.conf
sudo sysctl -p
sudo systemctl enable --now wg-quick@wg0
```

Open the WireGuard UDP port in your firewall (see `PRODUCTION_SECURITY.md`
for the full firewall picture — this is the *only* MikroTik-related port
that should be open to the public internet):

```bash
sudo ufw allow 51820/udp
```

## 2. MikroTik side (per router)

RouterOS has native WireGuard support (v7+). In Winbox/terminal:

```
/interface wireguard
add name=wg-vps listen-port=13231 private-key="<generate or paste>"

/interface wireguard peers
add interface=wg-vps public-key="<VPS server_public.key contents>" \
    endpoint-address=<VPS_PUBLIC_IP> endpoint-port=51820 \
    allowed-address=10.10.0.1/32 persistent-keepalive=25s

/ip address
add address=10.10.0.2/24 interface=wg-wgc
```

`persistent-keepalive=25s` matters — without it, a MikroTik behind NAT
(common — most ISP router deployments are behind CG-NAT or a plain NAT
router) will silently drop the tunnel after a few minutes of inactivity,
and the VPS won't notice until it tries to reach the router and fails.

Each router gets a unique tunnel IP (`10.10.0.2`, `10.10.0.3`, ...) —
that's the value that goes into `routers.wireguard_ip` when adding the
router in the admin panel (Phase 5).

## 3. Verify the tunnel

From the VPS:

```bash
sudo wg show
ping 10.10.0.2
```

`wg show` should list the peer with a recent "latest handshake" time — if
it's blank or very old, the MikroTik side isn't actually connecting
(check the endpoint address/port and that UDP 51820 is actually open,
not just configured).

## 4. Point Laravel at it

Once the tunnel is confirmed with `ping`, add the router in the admin
panel (Routers → Add Router) using:
- **WireGuard IP**: `10.10.0.2` (the tunnel IP, not the router's LAN or
  public IP)
- **API Port**: `8729` (API-SSL — see `PRODUCTION_SECURITY.md` for why
  this must be SSL, not plain 8728)

Then hit "Test Connection" — this is `MikrotikService::testConnection()`
actually calling `/system/resource/print` over the tunnel. If it fails,
the tunnel itself is usually fine (you already verified with `ping`) and
the problem is almost always the RouterOS API-SSL certificate setup,
covered next in `PRODUCTION_SECURITY.md`.
