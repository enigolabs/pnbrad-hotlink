# Phone normalization (captive portal)

Implemented in `/workspace/pnbrad/app/system/controllers/portal.php` (`portal_normalize_phone`).

1. Trim; strip spaces, dashes, parentheses, dots.
2. Leading `00` converted to `+`.
3. If starts with `+`, keep `+` + digits only (E.164-ish).
4. Else digits only. If Admin Settings `country_code_phone` is set and the digits do not already start with that country code, prepend it (after stripping one leading `0` from the local part).
5. Account: username = password = normalized phone; email = `{phone}@gmail.com`.

Not locked to a single country unless `country_code_phone` is configured in PHPNuxBill settings.
