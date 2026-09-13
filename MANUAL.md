# PNBrad / PHPNuxBill — Operator Manual

**Stack:** `agstr/pnb:latest` (Compose service `pnbrad`, container `PNBRAD`)  
**Local project:** `/workspace/pnbrad`  
**Web UI:** http://localhost:9980  
**Date of customizations:** 2026-09-13

This manual covers how the local stack is set up, how the new hotspot captive portal and Paystack flow work, and what was changed versus the stock Hub image.

---

## 1. What this stack is

PNBrad is AGSTR’s all-in-one Docker image: **PHPNuxBill + FreeRADIUS + MariaDB + phpMyAdmin + NGINX** (and related services). It is not a separate GitHub “pnbrad” app fork; the branded Compose service name is `pnbrad`.

| Piece | Role |
|-------|------|
| PHPNuxBill | Hotspot billing / captive portal / admin |
| FreeRADIUS | Auth / accounting for hotspot sessions |
| MariaDB | App database |
| NGINX | Serves the web UI (document root `/data/html`) |

Upstream app source (for reference): https://github.com/hotspotbilling/phpnuxbill  
Docker image: https://hub.docker.com/r/agstr/pnb

---

## 2. Quick start (this machine)

```bash
cd /workspace/pnbrad
docker compose up -d
```

| Service | Host access |
|---------|-------------|
| Web UI | http://localhost:9980 |
| RADIUS | UDP `1812`–`1813` |
| MariaDB | TCP `9306` → container `3306` |
| SSH into container | TCP `922` → container `22` |

Secrets for DB / RADIUS / container root are in **`/workspace/pnbrad/CREDENTIALS.md`** (local only — do not commit or share). Hub defaults were replaced with strong random values. Timezone remains `Asia/Jakarta` as in the official compose snippet. The container runs with `privileged: true` (required by the Hub compose).

### Useful URLs

| URL | Purpose |
|-----|---------|
| http://localhost:9980/?_route=portal | Hotspot captive portal (packages); MAC auto-pass if known |
| http://localhost:9980/?_route=portal/reconnect | Phone-only reconnect |
| http://localhost:9980/?_route=login | Redirects to portal; MAC auto-pass if known |
| http://localhost:9980/admin/ or `/?_route=admin` | Staff / admin login (unchanged) |
| `/?_route=portal&nux-mac=…&nux-ip=…` | Simulate MikroTik captive redirect |

---

## 3. End-user hotspot flow (new behavior)

Hotspot customers **never see a traditional username/password login page**.

### 3.1 Buy a package

1. Captive portal opens on **available Hotspot packages** (name, price, validity, quota).
2. User taps **Buy & Pay** on a package.
3. A **popup asks for phone number** only.
4. On submit the system **creates an account automatically** (if missing):
   - **Username** = normalized phone  
   - **Password** = same phone  
   - **Email** = `{digits-only phone}@gmail.com` (no `+`)
5. User is redirected **straight to Paystack** to pay for that plan.
6. After **verified** payment, the plan is activated and the browser is redirected to **https://www.google.com**.

### 3.2 Reconnect

On the home portal screen (top-right) there is a **Reconnect** button → `/?_route=portal/reconnect`.

1. User enters **phone number only** (no password).
2. System finds the account and reconnects if status allows and session context is available.
3. On a real MikroTik/hotspot redirect, `nux-mac` / `nux-ip` (or equivalent) from the captive redirect are needed for a full session restore. Local demo without those query params may be limited.

### 3.3 Returning device — MAC auto-pass

When a hotspot client returns with a **MAC already stored** and an **active package**:

1. Captive portal (or stock `/?_route=login`) receives `nux-mac` / `nux-ip` (MikroTik) or aliases (`mac`, `mac-address`, …).
2. System looks up `tbl_portal_macs` → customer.
3. If customer is not Banned and has `tbl_user_recharges.status = on`:
   - Logs the customer in
   - Calls device `connect_customer` (grant hotspot) when MAC+IP are present
   - Redirects to **status** page `/?_route=home` (not Google, not the package list)
4. Otherwise the normal packages + phone popup + Reconnect UI is shown.

**Persistence:** MAC → customer_id is upserted on successful Paystack return (`portal/paid`) and on successful phone reconnect. Canonical form is lowercase colon MAC (`aa:bb:cc:dd:ee:ff`); compare tolerates no-colon / mixed case.

**How to test locally**

```text
# Unknown MAC → packages UI (200)
http://localhost:9980/?_route=portal&nux-mac=11:22:33:44:55:66&nux-ip=10.0.0.99

# Known MAC + active package → 302 Location: …/?_route=home
http://localhost:9980/?_route=portal&nux-mac=AA:BB:CC:DD:EE:FF&nux-ip=10.0.0.50

# Same via login (stock hotspot landing)
http://localhost:9980/?_route=login&nux-mac=AA:BB:CC:DD:EE:FF&nux-ip=10.0.0.50

# Alias (no colons)
http://localhost:9980/?_route=portal&mac-address=AABBCCDDEEFF&nux-ip=10.0.0.50
```

Seed a row for lab tests: `INSERT INTO tbl_portal_macs (mac, customer_id, last_ip, last_seen) VALUES ('aa:bb:cc:dd:ee:ff', <customer_id>, '10.0.0.50', NOW());` and ensure that customer has a recharge with `status='on'`.

### 3.4 Phone normalization

Documented in `PHONE_NORMALIZATION.md`. Summary:

- Strip spaces, dashes, parentheses, dots.
- Leading `00` → `+`.
- Prefer E.164-style `+` + digits; otherwise digits-only.
- If Admin setting `country_code_phone` is set and the number has no country prefix, prepend it (after stripping one leading `0` from the local part).
- **Not** locked to one country unless that setting is configured.

---

## 4. Admin setup

### 4.1 Paystack (required for live payments)

1. Open **Admin → Payment Gateway → Paystack**.
2. Enter:
   - Public key (`pk_test_…` or `pk_live_…`)
   - Secret key (`sk_test_…` or `sk_live_…`)
   - Optional webhook secret
   - Currency (as used in your Paystack account)
3. Webhook URL (for Paystack Dashboard):  
   `http://<your-public-host>/?_route=callback/paystack`  
   On localhost, Paystack cannot reach your machine unless you use a tunnel (ngrok, Cloudflare Tunnel, etc.). **Redirect/verify-by-reference still works** without a public webhook for many test flows; webhook is recommended for production reliability.
4. Until real keys replace the placeholders (`pk_test_REPLACE_ME` / `sk_test_REPLACE_ME`), the portal fails fast with a clear warning instead of hanging on Paystack HTTP.

### 4.2 Hotspot plans

Create plans under **Admin → Services → Hotspot Plans**.  
Local demo plans were seeded for testing (e.g. Demo Hotspot 1 Day / 7 Days on device `Dummy`, RADIUS-enabled). Replace or disable demos before production.

### 4.3 Admin vs customer login

- **Customers / hotspot:** forced to the package portal (no password form).
- **Admin / staff:** still use `/admin` (or `/?_route=admin`) with normal credentials.

---

## 5. How files are laid out on this box

```
/workspace/pnbrad/
  docker-compose.yml      # Hub-based compose + bind mounts for customizations
  CREDENTIALS.md          # Local secrets (do not commit)
  CHANGELOG.md            # Engineering change log
  PHONE_NORMALIZATION.md  # Phone rules
  MANUAL.md               # This document
  SOURCES.md              # Where Hub / upstream sources came from
  app/                    # Editable extract of customized PHP / templates
  data/html/              # Persisted nginx webroot
  data/mysql/             # Persisted MariaDB data
```

**Important:** Inside the container, nginx serves **`/data/html`**, not `/app/html`. Customizations are bind-mounted from `./app/...` into `/data/html/...` so edits survive rebuilds when you keep this compose file.

After editing files under `app/`, recreate or restart the service if mounts were changed:

```bash
cd /workspace/pnbrad
docker compose up -d
```

---

## 6. Changes made (vs stock Hub image)

Full file-level detail: **`CHANGELOG.md`**. Summary:

### 6.1 Paystack payment gateway (new)

| Path | Purpose |
|------|---------|
| `app/system/paymentgateway/paystack.php` | Initialize payment, verify by reference, webhook HMAC (`x-paystack-signature`), admin config hooks |
| `app/system/paymentgateway/ui/paystack.tpl` | Admin UI for keys, webhook secret, currency, webhook URL |

Behavior:

- Creates Paystack transactions for selected hotspot plans.
- Activates service **only after** verified successful payment.
- Supports callback / notification routes used by PHPNuxBill’s payment gateway conventions.

### 6.2 Captive portal — packages, phone, auto-account (new)

| Path | Purpose |
|------|---------|
| `app/system/controllers/portal.php` | Portal home, buy → Paystack, post-pay activate + Google redirect, reconnect by phone; MAC auto-pass |
| `app/system/autoload/PortalMac.php` | MAC normalize / session capture / remember / autopass |
| `app/index.php` | Persist `nux-mac` / aliases + `nux-ip` into session |
| `app/ui/ui/customer/portal.tpl` | Package cards, **Reconnect** button, phone modal (no password) |
| `app/ui/ui/customer/portal-reconnect.tpl` | Phone-only reconnect page |
| DB `tbl_portal_macs` | Durable MAC → customer_id map |

### 6.3 Login behavior (changed)

| Path | Purpose |
|------|---------|
| `app/system/controllers/login.php` | Customer login display **redirects to `portal`**; runs MAC auto-pass first. Admin login untouched. |

### 6.4 Docker / persistence (changed)

| Path | Purpose |
|------|---------|
| `docker-compose.yml` | Persist `./data/mysql` and `./data/html`; bind-mount customized PHP/templates into `/data/html/...` |

### 6.5 Local ops artifacts (new)

| Path | Purpose |
|------|---------|
| `CHANGELOG.md` | Engineering changelog |
| `PHONE_NORMALIZATION.md` | Phone rules |
| `CREDENTIALS.md` | Hardened local secrets |
| `MANUAL.md` | This operator manual |
| Demo Hotspot plans in DB | Local try-out without MikroTik |

### 6.6 What was *not* changed

- Core FreeRADIUS / MariaDB image internals beyond env and mounts.
- Admin authentication UX.
- Laparoscopic simulator work (separate project under `/workspace/laparoscopic-simulator`).

---

## 7. Try the customized flow (checklist)

1. `docker compose up -d` from `/workspace/pnbrad`.
2. Open http://localhost:9980/?_route=portal — confirm packages and **Reconnect** button.
3. Admin → Payment Gateway → Paystack — set real keys.
4. Click a package → enter phone → confirm redirect toward Paystack.
5. Complete a **test** payment → confirm activation and redirect to Google.
6. Open Reconnect → enter same phone → confirm reconnect behavior (best with real hotspot `nux-mac` / `nux-ip`).
7. After a paid/reconnect session that had MAC+IP, revisit `/?_route=portal&nux-mac=<same>&nux-ip=<ip>` → should skip packages and land on `/?_route=home`.

---

## 8. Production notes / caveats

- **Public URL:** Paystack webhooks need a reachable HTTPS URL; localhost alone is not enough for webhooks.
- **Keys:** Never commit Paystack secret keys or `CREDENTIALS.md`.
- **Hardware hotspot:** Full reconnect/auth depends on router captive-portal redirect parameters and RADIUS; the Dummy device is for UI/payment demos.
- **Email:** Synthetic `{digits}@gmail.com` satisfies the schema; it is not a verified mailbox.
- **Image updates:** Pulling a newer `agstr/pnb` may overwrite unmanaged files under `/data/html`; keep customizations in `app/` + compose bind mounts (or re-apply from git).

---

## 9. Related links

- Docker Hub: https://hub.docker.com/r/agstr/pnb  
- Upstream PHPNuxBill: https://github.com/hotspotbilling/phpnuxbill  
- Local changelog: `/workspace/pnbrad/CHANGELOG.md`  
- Local credentials: `/workspace/pnbrad/CREDENTIALS.md`


## MikroTik login + router name

Use `/workspace/pnbrad/mikrotik-hotspot/login.html` on the router (Hotspot folder).

- Set `HOTLINK_URL` to this PHPNuxBill host.
- `nux-router` is the **router name** (`$(identity)`), matching **Admin → Networks → Routers** (numeric id still works).
- Walled Garden must allow this host and Paystack before auth.
