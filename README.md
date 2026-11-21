# Agentur Hub for REDAXO 6 (`klxm_hub`)

This addon lets a REDAXO 6 website take part in the **Agentur Hub** (the `agentur` extension of KLXM Studio):

- **Sign-in via the hub** – agency staff sign in to the backend via OpenID Connect (single sign-on, a second factor is
  required). The login page shows a “Sign in with Agentur Hub” button; “Open” in the hub signs in directly.
- **Monitoring** – a signed, read-only status endpoint (protocol `status-v1`): versions of REDAXO and PHP, addons,
  traffic light of the checks, latest backup, error log (counts only). No content, no personal data.

Both are **off by default** and do not change how the website looks. Deutsch: [README.de.md](README.de.md)

## Installation

```bash
composer require klxm/klxm_hub
php bin/console migrate
```

## Setup

1. **In the hub:** Manage → Register instance → system “REDAXO 6”, website address, backend path (usually `/redaxo`).
   The addresses are prefilled:

   | | Address |
   |---|---|
   | Redirect URI | `https://<host>/redaxo/index.php?rex-api-call=klxm_hub&step=callback` |
   | Initiate login | `https://<host>/redaxo/index.php?rex-api-call=klxm_hub&step=start` |
   | Status | `https://<host>/index.php?rex-api-call=klxm_hub_status` |
   | After sign-out | `https://<host>/redaxo/index.php` |

   Client ID and client secret are shown **once**. Grant access per person with role `admin` or `redaktion`.
2. **In REDAXO:** Addons → Agentur Hub → Settings: issuer (e.g. `https://hub.klxm.de/hub`), client ID, client secret,
   role for `redaktion`, “Offer sign-in via the provider” → Save (the addon checks the discovery document, otherwise
   it stays off). “Addresses for the hub” shows the exact addresses this installation expects (based on `REX_BASE_URL`).
   Monitoring: enter the hub's public key (`ed25519:…`), “Allow signed status requests” → Save.
3. **In the hub:** “Check now” – the traffic light appears.
4. **Keep an emergency admin** with password (and passkey). The addon warns when there is none.

## Agency accounts

Mapped by issuer + `sub` (table `rex_klxm_hub_identity`), never by e-mail. Created on first sign-in (optional) without
a password and marked “Agency account”; role from claim `roles` (`admin` → admin, `redaktion` → configured role),
synchronised on every sign-in. Existing users are never taken over. Lock them under Addons → Agentur Hub → Agency
accounts (running sessions end immediately).

## Security

PKCE S256, `state` bound to the backend session (single use, 10 minutes), `nonce`, RFC 9207 `iss`; ID token RS256 only
via JWKS (key rotation: unknown `kid` → reload once), checks of `iss`, `aud`/`azp`, `exp`/`iat` (±60 s), `nonce`,
`at_hash`, `sub`; `amr` must prove a second factor. Provider endpoints https only (http with `REX_MODE=dev`). Client
secret encrypted with libsodium (key file outside the database) or from `KLXM_HUB_CLIENT_SECRET`. Rate limits per IP,
log lines `klxm_hub: …` in the REDAXO system log without secrets.

Status endpoint: off → `404`; rate limit → `429`; header format, ±300 s window, key ID, Ed25519 signature → `401`; the
nonce is remembered only after a valid signature (replay → `401`); `200` with JSON. Error responses carry no reasons.

## Developers

Extension points `Klxm\Hub\ExtensionPoint\HubStatus` (extend the status, e.g. backups of your tool, own checks) and
`HubUserMapping` (adjust permissions of an agency account, `null` refuses the sign-in) – examples in
[README.de.md](README.de.md#für-entwickler). Self test without network: `php bin/console klxm_hub:selftest`.

Not (yet) offered by the core: a public method to sign in a user (the addon mirrors the passkey login and uses the
`@internal` `UserSession`), an extension point on the login page (the button is added via `OUTPUT_FILTER`), a hook
before the backend logout (RP-initiated logout is not implemented yet).

## License

MIT – see [LICENSE](LICENSE). KLXM Crossmedia GmbH.
