# HotLink / PNBrad + MikroTik — Workflow & Setup

**Brand:** HotLink (PHPNuxBill inside PNBrad)  
**Project path:** `/workspace/pnbrad`  
**MikroTik login page:** `/workspace/pnbrad/mikrotik-hotspot/login.html`

---

## Part A — How the workflow works

### Actors

| Piece | Job |
|-------|-----|
| **MikroTik Hotspot** | Captures the client; serves `login.html`; later grants internet after PHPNuxBill asks it to (API / RADIUS) |
| **PNBrad / PHPNuxBill** | Packages, phone signup, Paystack, MAC memory, status page |
| **Paystack** | Collects payment; callback/verify activates the plan |
| **FreeRADIUS** (in PNBrad image) | Auth/accounting when plans are RADIUS-backed |

### End-to-end flows

#### 1) First-time visitor (buy)

```
Phone joins Wi‑Fi
    → MikroTik Hotspot captive portal
    → login.html (no password form)
    → redirect to HotLink:
         /?_route=portal&nux-mac=…&nux-ip=…&nux-router=<Identity>
    → Package list (HotLink)
    → User taps Buy & Pay
    → Phone popup
    → Account auto-created:
         username = phone
         password = phone
         email    = {digits-only phone}@gmail.com
    → Paystack checkout
    → Payment verified
    → Plan activated + MAC saved (tbl_portal_macs)
    → MikroTik connect_customer (if nux-mac + nux-ip present)
    → Browser → https://www.google.com
```

#### 2) Returning visitor (same phone / same MAC, active package)

```
Phone joins Wi‑Fi again
    → login.html → portal with nux-mac + nux-ip
    → tbl_portal_macs finds customer
    → Active recharge (status = on)?
         YES → login + connect → /?_route=home (status) → browsing
         NO  → package list / Reconnect
```

#### 3) Reconnect by phone (button on portal)

```
Portal → Reconnect → enter phone only
    → Find account + active package
    → Login + connect_customer
    → Google (same as after pay)
```

### What never happens for hotspot customers

- No classic username/password login page (customer `/?_route=login` redirects to portal).
- Admin/staff login stays at `/admin`.

### Key URLs (on the billing server)

| URL | Purpose |
|-----|---------|
| `/?_route=portal` | Packages + Reconnect button |
| `/?_route=portal/reconnect` | Phone-only reconnect |
| `/?_route=home` | Customer status (MAC auto-pass lands here) |
| `/admin/` | Admin |
| Admin → Payment Gateway → Paystack | Keys + webhook URL |

### Data the MikroTik page must send

| Query | Source | Why |
|-------|--------|-----|
| `nux-mac` | `$(mac-esc)` | Identify device; MAC auto-pass |
| `nux-ip` | `$(ip)` | `connect_customer` |
| `nux-hostname` | `$(hostname)` | Optional / CHAP helpers |
| `nux-router` | `$(identity)` or override | Filter plans to this router **name** |

`nux-router` must match **Admin → Networks → Routers → Name**, not the numeric id (ids still work if you prefer).

---

## Part B — Setup PNBrad (billing server)

### B1. Requirements

- Docker + Compose
- Host ports free: **9980** (web), **1812–1813/udp** (RADIUS), optional **9306** (MySQL), **922** (SSH into container)
- A URL/IP the MikroTik (and phones) can reach for the portal — **not** only `localhost` if the router is elsewhere
- Paystack test or live keys

### B2. Start the stack

```bash
cd /workspace/pnbrad   # or your deploy copy
docker compose up -d
```

Open: `http://<server-ip>:9980/admin/`

Secrets for this lab box: `/workspace/pnbrad/CREDENTIALS.md` (do not commit).

### B3. First admin login

1. Log in with the image’s default admin (check PNBrad / PHPNuxBill docs for first-boot credentials; change password immediately).
2. Set company name, currency, timezone, optional `country_code_phone` (Settings) for local phone formatting.

### B4. Add the MikroTik as a router

1. **Admin → Networks → Routers → Add**
2. **Name** = exactly the MikroTik **System → Identity** string (or set `ROUTER_NAME` in `login.html` to this name).
3. Fill IP, API user/password (or RADIUS settings if you use RADIUS plans).
4. Save and test connectivity from PHPNuxBill if the UI offers it.

### B5. Create Hotspot plans

1. **Admin → Services → Hotspot Plans**
2. Create prepaid plans (price, validity, quota).
3. Assign **routers** to the router name from B4, **or** enable RADIUS (`is_radius`) if that is your model.
4. Ensure **enabled**, **prepaid**, **allow purchase**.

### B6. Paystack

1. **Admin → Payment Gateway → Paystack**
2. Enter public key (`pk_…`) and secret key (`sk_…`), currency, optional webhook secret.
3. Enable Paystack in active gateways.
4. Webhook URL (production): `https://<your-public-host>/?_route=callback/paystack`  
   Localhost alone cannot receive Paystack webhooks without a tunnel (ngrok, Cloudflare Tunnel). Redirect verify still works for many tests.

### B7. Confirm portal locally

```text
http://<server>:9980/?_route=portal
http://<server>:9980/?_route=portal&nux-mac=11:22:33:44:55:66&nux-ip=10.0.0.99
```

You should see packages (or MAC auto-pass if that MAC is known and active).

### B8. Firewall on the server

Allow from the MikroTik / hotspot LAN (and internet if needed):

- TCP **9980** (or 80/443 if you reverse-proxy)
- UDP **1812–1813** if RADIUS is used between MikroTik and PNBrad

---

## Part C — Setup MikroTik Hotspot

### C1. Basics

1. Set **System → Identity** to the same name as PHPNuxBill’s router (B4).
2. Create Hotspot on the customer interface (usual Hotspot setup wizard / IP → Hotspot).
3. Ensure DNS works for clients (otherwise captive + Paystack fail).

### C2. Install HotLink `login.html`

1. Edit `/workspace/pnbrad/mikrotik-hotspot/login.html`:
   - Set `HOTLINK_URL` to the **reachable** PHPNuxBill URL, e.g. `http://192.168.88.2:9980` or `https://hotlink.example.com`
   - Leave `ROUTER_NAME = "$(identity)"` unless Identity ≠ PHPNuxBill name
2. Winbox → **Files** → open the `hotspot` folder (or Hotspot profile HTML directory).
3. Upload / overwrite **`login.html`**.
4. Keep default `md5.js`, `alogin.html`, `status.html`, `logout.html`, `error.html` unless you have custom ones.

### C3. Walled Garden (critical)

**IP → Hotspot → Walled Garden** (and/or Walled Garden IP), add:

| Entry | Why |
|-------|-----|
| PHPNuxBill IP or domain | Portal / API before auth |
| Port if non-80 (e.g. `:9980`) as your ROS version requires | Same |
| `paystack.com` / `*.paystack.com` (and any Paystack CDN hosts you see in checkout) | Payment page before auth |

Without this, phones never load HotLink or Paystack while still captive.

### C4. RADIUS or API (match your plans)

**If plans use RADIUS**

- Point Hotspot RADIUS to PNBrad (`<server-ip>`, ports 1812/1813).
- Shared secret = value in PNBrad / `CREDENTIALS.md` / FreeRADIUS config (must match).
- NAS / client entry for the MikroTik IP in RADIUS as required by the image.

**If plans use MikroTik API (non-RADIUS plans)**

- PHPNuxBill router row must have correct API user, password, and IP.
- API user needs permissions to manage hotspot active users / cookies as PHPNuxBill expects.

### C5. Profile / login-by

- Hotspot profile should present the HTML login page (default).
- Cookie / MAC cookie optional; HotLink also persists MAC in `tbl_portal_macs`.

### C6. Quick test from a phone

1. Connect to the Hotspot SSID.
2. Captive browser should flash HotLink then show **packages** (or status if returning).
3. Buy with phone → Paystack test card → land on Google and have internet.
4. Disconnect / reconnect: should hit **status** and browse without buying again while the package is active.

---

## Part D — Checklist

### PNBrad

- [ ] `docker compose up -d`
- [ ] Admin password changed
- [ ] Router created; **name = Identity**
- [ ] Hotspot plans for that router (or RADIUS)
- [ ] Paystack keys live (not `REPLACE_ME`)
- [ ] Port 9980 (or HTTPS) reachable from hotspot network
- [ ] RADIUS ports open if used

### MikroTik

- [ ] Identity matches router name
- [ ] Hotspot enabled on customer interface
- [ ] `login.html` uploaded with correct `HOTLINK_URL`
- [ ] Walled Garden: billing host + Paystack
- [ ] RADIUS or API credentials match PNBrad
- [ ] DNS for clients OK

### Go-live extras

- [ ] Public HTTPS for PHPNuxBill (recommended)
- [ ] Paystack webhook URL on public host
- [ ] Remove demo plans / Dummy device
- [ ] Backup `data/mysql` and customized `app/` files

---

## Part E — Troubleshooting

| Symptom | Likely cause |
|---------|----------------|
| Captive page never opens HotLink | Wrong `HOTLINK_URL`; not in Walled Garden; DNS |
| Packages empty | No plans / wrong router name / purchase disabled |
| Paystack blank / fails | Keys placeholders; Paystack not in Walled Garden |
| Paid but no internet | No `nux-mac`/`nux-ip`; API/RADIUS misconfigured; router offline |
| Returning user sees packages again | MAC not saved (pay/reconnect never completed); package expired; MAC changed (randomized MAC on phone) |
| Router name filter wrong | Identity ≠ Routers name; fix Identity or `ROUTER_NAME` |

---

## Files reference

| Path | Role |
|------|------|
| `docker-compose.yml` | PNBrad service + bind mounts |
| `app/system/controllers/portal.php` | Portal, Paystack buy, MAC auto-pass, reconnect |
| `app/system/autoload/PortalMac.php` | MAC table helpers |
| `app/system/paymentgateway/paystack.php` | Paystack plugin |
| `mikrotik-hotspot/login.html` | Router captive page |
| `MANUAL.md` | Operator manual / changelog of customizations |
| `CREDENTIALS.md` | Local secrets (private) |

---

*Generated for the HotLink / PNBrad customization (2026-09-13).*
