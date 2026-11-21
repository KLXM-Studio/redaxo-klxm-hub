# Changelog

## 0.1.3 (2026-11-21)

Sicherheitsprüfung (Anmeldung, Status-Abfrage, Logo) – Härtungen, keine neuen Funktionen:

- Abrufe beim Anbieter (Discovery, JWKS, Token, Logo) gehen nicht mehr an private, lokale oder reservierte Adressen
  (127/8, 10/8, 172.16/12, 192.168/16, 169.254/16, 100.64/10, ::1, fc00::/7, fe80::/10, IPv4 in IPv6 …); geprüft wird
  die aufgelöste Adresse, verbunden wird genau mit ihr (kein DNS-Rebinding). Ausnahmen: Modus `dev` oder
  `KLXM_HUB_ALLOW_PRIVATE_NETWORK=1` (Hub im eigenen Netz, Proxy mit privater Adresse).
- Logo: Bereinigung baut das SVG neu auf (nur Formen im SVG-Namensraum, erlaubte Attribute mit harmlosen Werten) statt
  Teile zu entfernen – keine CDATA, Texte, Namensraum-Deklarationen, fremden Namensräume, CSS-Escapes; nur UTF-8 (eine
  in UTF-7 versteckte DTD wurde bisher gelesen). Die lokale Kopie wird bei jeder Ausgabe erneut bereinigt.
- Status-Abfrage: Nonces werden 660 statt 600 Sekunden gemerkt – eine Anfrage mit Zeitstempel +300 s ließ sich genau
  an der Grenze des Zeitfensters noch einmal abspielen.
- Agentur-Konten: eine vom Anbieter als unbestätigt gemeldete E-Mail-Adresse (`email_verified` ≠ true) wird weder
  Login noch E-Mail des Kontos (dann `hub-…`).
- ID-Token: RSA-Schlüssellänge auf das Bit genau (≥ 2048), Exponent ungerade und ≥ 3; `aud`, `amr`, `roles` zählen nur
  Texte (verschachtelte Werte lösten im Modus `dev` eine Ausnahme aus).
- Anmeldeseite: Link zum Einstieg relativ (hängt nie am Host-Header); Weiterleitungen mit `Cache-Control: no-store`;
  weitere offene Anmeldungen der Sitzung verfallen nach erfolgreicher Anmeldung. Einstellungen warnen, wenn
  `REX_BASE_URL` fehlt (Redirect-URI hängt sonst am Host der Anfrage).
- Selbsttest um diese Fälle erweitert.

## 0.1.2 (2026-11-21)

- Anmeldeseite: Agentur-Link in Textfarbe (Kontrast auf dunklem Login).

## 0.1.1 (2026-11-21)

- Anmeldeseite: Agentur-Login dezent unter dem Formular (Passkey und Passwort bleiben vorn), mit Logo des Anbieters aus der Discovery (`logo_uri`, beim Speichern geladen, streng bereinigt, lokal abgelegt – nie ein Abruf beim Anzeigen); Befehl `klxm_hub:logo`.

## 0.1.0 – 2026-11-21

Erste Fassung für REDAXO 6.

- Anmeldung im Backend über den Agentur Hub (OpenID Connect): PKCE S256, state an die Backend-Sitzung gebunden,
  nonce, RFC 9207 `iss`, ID-Token RS256 über JWKS mit Schlüsselwechsel, `at_hash`, zweiter Faktor über `amr`.
  Einstieg vom Hub (`step=start&iss=…&login_hint=…`) und Knopf auf der Anmeldeseite.
- Agentur-Konten: Zuordnung über Issuer + `sub`, Anlage bei der ersten Anmeldung (abschaltbar), ohne Passwort,
  gekennzeichnet; Rollen aus dem Claim `roles` (`admin`, `redaktion` → einstellbare Rolle), Abgleich bei jeder
  Anmeldung; vorhandene Benutzer werden nie übernommen; lokal sperrbar (Sitzungen enden sofort).
- Signierte Status-Abfrage `status-v1` (`rex-api-call=klxm_hub_status`): Ed25519, ±300 s, Nonce erst nach gültiger
  Signatur; Versionen, AddOns, Ampel, letzte Sicherung, Fehlerprotokoll (Feld `log`).
- Einstellungen (AddOns → Agentur Hub): Client-Geheimnis verschlüsselt bzw. aus `KLXM_HUB_CLIENT_SECRET`, Adressen
  für den Hub, Vorschau der Status-Antwort; Seite „Agentur-Konten“.
- Rate-Limit je IP, Protokoll im Systemprotokoll, Erweiterungspunkte `HubStatus` und `HubUserMapping`,
  Selbsttest `klxm_hub:selftest`. Alles standardmäßig aus.
