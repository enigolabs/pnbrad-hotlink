# pnbrad local CHANGELOG — Paystack + Hotspot captive portal

Date: 2026-09-13

## Source extract
- Extracted live app from container `PNBRAD` path `/app/html` → `/workspace/pnbrad/app`
- **Important:** nginx document root is `/data/html` (not `/app/html`). Bind-mounts target `/data/html/...`.
- Persisted runtime webroot + DB under `/workspace/pnbrad/data/{html,mysql}`.
- Paymentgateway folder in the image was empty; Paystack plugin written to match PHPNuxBill conventions (referenced Focuslinks Paystack + official Flutterwave gateway shapes). No full GitHub app clone.

## Files created
| Path | Why |
|------|-----|
| `/workspace/pnbrad/app/system/paymentgateway/paystack.php` | Paystack gateway: initialize, verify-by-reference, webhook HMAC SHA512 (`x-paystack-signature`), admin config hooks (`_validate_config`, `_show_config`, `_save_config`, `_create_transaction`, `_payment_notification`, `_get_status`). |
| `/workspace/pnbrad/app/system/paymentgateway/ui/paystack.tpl` | Admin UI for public key, secret key, webhook secret, currency; shows webhook URL. |
| `/workspace/pnbrad/app/system/controllers/portal.php` | Captive portal: Hotspot packages → phone popup → auto-create user → Paystack; paid → activate → Google; reconnect by phone only. Phone normalization helper documented in-file. |
| `/workspace/pnbrad/app/ui/ui/customer/portal.tpl` | Package cards + phone modal (no password form). |
| `/workspace/pnbrad/app/ui/ui/customer/portal-reconnect.tpl` | Phone-only reconnect page. |
| `/workspace/pnbrad/CHANGELOG.md` | This file. |

## Files changed
| Path | Why |
|------|-----|
| `/workspace/pnbrad/app/system/controllers/login.php` | Customer login display redirects to `portal` (no username/password for hotspot users). Admin `/admin` untouched. |
| `/workspace/pnbrad/docker-compose.yml` | Bind-mounts for changed paths into `/data/html/...`; persist `./data/mysql` and `./data/html`. DB/RADIUS env unchanged. |

## DB seed (local runtime)
- Demo Hotspot plans: `Demo Hotspot 1 Day` (1000), `Demo Hotspot 7 Days` (5000), device `Dummy`, `is_radius=1`.
- `payment_gateway=paystack` with placeholders `pk_test_REPLACE_ME` / `sk_test_REPLACE_ME`.

## Phone normalization assumption
- Strip spaces, dashes, parentheses, dots.
- Leading `00` → `+`.
- Keep E.164-ish `+` + digits, or digits-only.
- If Settings `country_code_phone` is set and the number has no `+`/`00` and does not already start with those country-code digits, prepend it (strip one leading 0 from the local part).
- Username = password = normalized phone; email = `{phone}@gmail.com`.
- Does **not** hard-lock to one country unless admin sets `country_code_phone`.

## Paystack keys
- **Still needed from V** (test or live). Placeholders fail fast with a clear portal warning (no hang on Paystack HTTP).
- After keys are saved: Admin → Payment Gateway → Paystack. Webhook URL: `http://<host>/?_route=callback/paystack`.

## How to try
- Portal / packages: `http://localhost:9980/?_route=portal` (also `/?_route=login` → 302 to portal)
- Reconnect: `http://localhost:9980/?_route=portal/reconnect`
- Admin login: `http://localhost:9980/admin/` or `/?_route=admin` (username/password still work)
- Paystack settings: Admin → Payment Gateway → Paystack

## Additional docs
| Path | Why |
|------|-----|
| `/workspace/pnbrad/PHONE_NORMALIZATION.md` | Phone normalization assumptions for portal. |
| `/workspace/pnbrad/data/html` | Persisted nginx webroot (`/data/html`). |
| `/workspace/pnbrad/data/mysql` | Persisted MariaDB datadir. |


## 2026-09-13 follow-up
- Portal email is now `{digits-only phone}@gmail.com` (no `+`). Existing portal customers are updated on next buy/find.

## 2026-09-13 — MAC auto-pass for returning hotspot users

### Behavior
- If captive portal / login is hit with a MAC already mapped in DB **and** the customer has an active package (`tbl_user_recharges.status = on`) and is not Banned:
  - Auto login (`portal_login_customer` equivalent)
  - Call `connect_customer` when `nux-mac` + `nux-ip` are present
  - Redirect to customer status page `/?_route=home` (not Google)
- Unknown MAC or no active package: unchanged packages + phone popup + Reconnect flow.
- On successful Paystack return (`portal/paid`) and successful phone reconnect: upsert MAC → customer_id.

### Schema — `tbl_portal_macs` (new)
```sql
CREATE TABLE IF NOT EXISTS tbl_portal_macs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  mac VARCHAR(17) NOT NULL COMMENT 'canonical lowercase colon form aa:bb:cc:dd:ee:ff',
  customer_id INT UNSIGNED NOT NULL,
  last_ip VARCHAR(45) DEFAULT NULL,
  last_seen DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mac (mac),
  KEY idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
- Canonical storage: lowercase colon MAC (`aa:bb:cc:dd:ee:ff`).
- Lookup accepts colon / no-colon / mixed case (normalized before compare).
- One MAC → one current customer (upsert on remember).

### Files created / changed
| Path | Why |
|------|-----|
| `app/system/autoload/PortalMac.php` | Normalize, capture GET aliases, remember, lookup, tryAutopass |
| `app/system/controllers/portal.php` | Autopass on list; remember on paid + reconnect |
| `app/system/controllers/login.php` | Autopass before redirect to portal (stock hotspot URL) |
| `app/index.php` | Accept `mac` / `mac-address` / etc. aliases into session |
| `docker-compose.yml` | Bind-mounts for `PortalMac.php` + `index.php` |
| `CHANGELOG.md` / `MANUAL.md` | Document flow + test URLs |

### How to test
- Unknown MAC (packages): `http://localhost:9980/?_route=portal&nux-mac=11:22:33:44:55:66&nux-ip=10.0.0.99`
- Known MAC + active package (302 → home): `http://localhost:9980/?_route=portal&nux-mac=AA:BB:CC:DD:EE:FF&nux-ip=10.0.0.50`
- Via login: `http://localhost:9980/?_route=login&nux-mac=AA:BB:CC:DD:EE:FF&nux-ip=10.0.0.50`
- Alias form: `http://localhost:9980/?_route=portal&mac-address=AABBCCDDEEFF&nux-ip=10.0.0.50`

## 2026-09-13 nux-router by name
- Portal and order accept `nux-router` as router **name** (or numeric id). MikroTik `login.html` sends `$(identity)`.
