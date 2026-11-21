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
2. **In REDAXO:** Addons → Agentur Hub → Settings: issuer (e.g. `https://hub.example.org/hub`), client ID, client secret,
   role for `redaktion`, “Offer sign-in via the provider” → Save (the addon checks the discovery document, otherwise
   it stays off). “Addresses for the hub” shows the exact addresses this installation expects (based on `REX_BASE_URL`).
   **Set `REX_BASE_URL`** (e.g. in `.env.local`), otherwise the redirect URI depends on the request's Host header – the
   settings warn about it.
   Monitoring: enter the hub's public key (`ed25519:…`), “Allow signed status requests” → Save.
3. **In the hub:** “Check now” – the traffic light appears.
4. **Keep an emergency admin** with password (and passkey). The addon warns when there is none.

## With your own Agentur Hub

The addon is not tied to a particular hub: it works with any Agentur Hub (extension `agentur` on a KLXM Studio
installation, [KLXM-Studio/studio-agentur-hub](https://github.com/KLXM-Studio/studio-agentur-hub)) and with any OpenID Connect provider or monitoring service that implements the open specifications –
[OIDC profile](https://github.com/KLXM-Studio/studio/blob/main/docs/agentur-hub/oidc-profil.md) and
[`status-v1`](https://github.com/KLXM-Studio/studio/blob/main/docs/agentur-hub/monitoring-status-v1.md).

**Requirements:** REDAXO 6, PHP ≥ 8.5 with `sodium` and `openssl`, the website on **https**, `REX_BASE_URL` set. The provider must
offer discovery, the authorization code flow with **PKCE S256**, `iss` in the response (RFC 9207), **RS256** ID tokens,
`client_secret_basic` or `client_secret_post`, an **`amr`** claim proving a second factor and a **`roles`** claim with `admin` or
`redaktion` (the addon requests `openid email profile roles`). The Agentur Hub provides all of this; for other providers configure
the role claim and `amr` there. Monitoring needs the service's public key `ed25519:…`.

**In the hub:** register the instance (system “REDAXO 6”, website address, backend path), make sure the addresses match the box
“Addresses for the hub” in the addon **exactly**, note client ID and secret (shown once), grant people `admin` or `redaktion`,
copy the monitoring key.

**In the addon:** Addons → Agentur Hub → Settings: name, issuer (e.g. `https://hub.example.org/hub`), client ID and secret
(or `KLXM_HUB_CLIENT_SECRET`), role for `redaktion`, account creation on first sign-in, enable sign-in (the addon checks the
discovery document); monitoring keys and “Allow signed status requests”.

**Check:** no warning in “Addresses for the hub”; the login page shows “Sign in with Agentur Hub”; “Open” in the hub signs in;
“Check now” shows the traffic light (yellow for “integrity not checked” is expected on REDAXO and can be acknowledged in the
hub); `php bin/console klxm_hub:selftest`; log lines `klxm_hub: …` in the REDAXO system log.

## Agency accounts

Mapped by issuer + `sub` (table `rex_klxm_hub_identity`), never by e-mail. Created on first sign-in (optional) without
a password and marked “Agency account”; role from claim `roles` (`admin` → admin, `redaktion` → configured role),
synchronised on every sign-in. Existing users are never taken over. An e-mail address the hub reports as unverified
(`email_verified` ≠ true) becomes neither login nor e-mail of the account. Lock accounts under Addons → Agentur Hub →
Agency accounts (running sessions end immediately). Agency accounts without a password sign in via the hub only: a
session created by passkey is ended at once (log `login-denied reason=passkey`), so revoking access in the hub works even
if the account has a local passkey.

## Self-description (`klxm-instance-v1`)

`GET /.well-known/klxm-studio` returns the instance name (`REX_INSTANCE_NAME`), redirect URI, initiate-login URI,
post-logout URI and status address (`"system": "redaxo6"`) so “Detect addresses” in the hub can take them over. `404` as
long as neither sign-in (issuer or client ID) nor monitoring is set up. No versions, keys or personal data. It works when
the frontend passes addresses without a file to `index.php` (rewrite rule); otherwise
`GET /index.php?rex-api-call=klxm_hub_instance` returns the same content.

## Security

PKCE S256, `state` bound to the backend session (single use, 10 minutes), `nonce`, RFC 9207 `iss`; ID token RS256 only
via JWKS (key rotation: unknown `kid` → reload once), checks of `iss`, `aud`/`azp`, `exp`/`iat` (±60 s), `nonce`,
`at_hash`, `sub`; RSA keys ≥ 2048 bits (exact), odd exponent ≥ 3; `jku`/`x5u`/`jwk` headers are never used; `amr`
must prove a second factor. Provider endpoints https only (http with `REX_MODE=dev`). Requests to the provider
(discovery, JWKS, token, logo) never go to private, local or reserved addresses (127/8, 10/8, 172.16/12, 192.168/16,
169.254/16, 100.64/10, ::1, fc00::/7, fe80::/10, IPv4-mapped IPv6 …); the resolved address is checked and used for the
connection (no DNS rebinding) – exceptions: `REX_MODE=dev` or `KLXM_HUB_ALLOW_PRIVATE_NETWORK=1` (hub in your own
network, outgoing proxy with a private address). The provider logo is rebuilt as a shapes-only SVG (no text, scripts,
styles, references, events, foreign namespaces; UTF-8 only, no DTD) and re-sanitised on every output. Client secret
encrypted with libsodium (key file outside the database) or from `KLXM_HUB_CLIENT_SECRET`. Rate limits per IP, log
lines `klxm_hub: …` in the REDAXO system log without secrets.

Status endpoint: off → `404`; rate limit → `429`; header format, ±300 s window, key ID, Ed25519 signature → `401`; the
nonce is remembered only after a valid signature, for 11 minutes – longer than the whole 2 × 300 s window (replay →
`401`); `200` with JSON. Error responses carry no reasons.

## Developers

Extension points `Klxm\Hub\ExtensionPoint\HubStatus` (extend the status, e.g. backups of your tool, own checks) and
`HubUserMapping` (adjust permissions of an agency account, `null` refuses the sign-in) – examples in
[README.de.md](README.de.md#für-entwickler). Self test without network: `php bin/console klxm_hub:selftest`.

Not (yet) offered by the core: a public method to sign in a user (the addon mirrors the passkey login and uses the
`@internal` `UserSession`), an extension point on the login page (the button is added via `OUTPUT_FILTER`), a hook
before the backend logout (RP-initiated logout is not implemented yet).

## License

MIT – see [LICENSE](LICENSE). KLXM Crossmedia GmbH.
