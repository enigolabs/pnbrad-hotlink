# PNBrad / HotLink — PHPNuxBill customizations

Private project for **HotLink** captive portal on AGSTR `agstr/pnb` (PHPNuxBill + FreeRADIUS + MariaDB).

> Customizations bind-mount over the Docker image. Runtime DB/webroot under `data/` are **not** in git.

## Docs

| Doc | Contents |
|-----|----------|
| [SETUP-AND-WORKFLOW.md](SETUP-AND-WORKFLOW.md) | Full HotLink + MikroTik workflow & setup |
| [MANUAL.md](MANUAL.md) | Operator manual |
| [CHANGELOG.md](CHANGELOG.md) | Customization changelog |
| [PHONE_NORMALIZATION.md](PHONE_NORMALIZATION.md) | Phone / email rules |
| [mikrotik-hotspot/](mikrotik-hotspot/) | Router `login.html` (no password form) |

## Features

- Paystack payment gateway
- Package-first captive portal (no customer login page)
- Phone signup → Paystack → Google; reconnect by phone
- Email = `{digits-only phone}@gmail.com`
- MAC auto-pass to status when package active
- `nux-router` accepts **router name** (`$(identity)`)

## Quick start

```bash
cp CREDENTIALS.example.md CREDENTIALS.md   # fill locally
# edit docker-compose.yml secrets / env
docker compose up -d
```

UI: http://localhost:9980/?_route=portal  
Admin: http://localhost:9980/admin/

See **SETUP-AND-WORKFLOW.md** for MikroTik + Paystack.

## License

Base app is PHPNuxBill / AGSTR image — follow their licenses. Custom portal/Paystack/MikroTik files in this repo are project-specific.
