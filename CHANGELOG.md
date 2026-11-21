# Changelog

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
