# HotLink MikroTik hotspot pages

Upload the contents of this folder into the router’s **hotspot** directory (Winbox → Files → `hotspot/`). Overwrite `login.html`.

## 1. Edit `login.html`

Find `HOTLINK_URL` and set it to the public URL of PHPNuxBill / pnbrad, for example:

- `http://203.0.113.10:9980`
- `https://hotlink.yourdomain.com`

## 2. Walled Garden (required)

Hotspot → Walled Garden → add the billing host so phones can reach the portal and Paystack **before** they are authenticated:

- Your HotLink / PHPNuxBill IP or domain
- `paystack.com` and `*.paystack.com` (or Paystack’s current checkout hosts)

## 3. What this page does

There is **no MikroTik username/password form**.

The captive portal immediately sends the client to HotLink with:

- `nux-mac=$(mac-esc)`
- `nux-ip=$(ip)`
- `nux-hostname=$(hostname)`

Then PHPNuxBill:

- Known MAC + active package → status page and browse
- Otherwise → package list → phone → Paystack

## 4. Keep MikroTik’s other hotspot files

You only **must** replace `login.html`. Leave `md5.js`, `alogin.html`, `status.html`, `logout.html`, `error.html` from the default hotspot folder unless you have custom ones.


## Router name (`nux-router`)

`login.html` sends `nux-router=$(identity)` (MikroTik **System → Identity**).

That string must match the router **name** in PHPNuxBill (**Admin → Networks → Routers**), not the numeric ID.

If identity and the PHPNuxBill name differ, set `ROUTER_NAME` in `login.html` to the PHPNuxBill name.

The portal accepts either an ID or a name.
