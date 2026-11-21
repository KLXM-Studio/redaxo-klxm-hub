# Agentur Hub für REDAXO 6 (`klxm_hub`)

Mit diesem AddOn nimmt eine REDAXO-6-Website am **Agentur Hub** teil (Erweiterung `agentur` von KLXM Studio):

- **Anmeldung über den Hub** – Mitarbeiter der Agentur melden sich im Backend per OpenID Connect an (Einmal-Anmeldung,
  zweiter Faktor ist Pflicht). Auf der Anmeldeseite erscheint der Knopf „Mit Agentur Hub anmelden“; „Öffnen“ im Hub
  meldet direkt an.
- **Überwachung** – eine signierte, lesende Status-Abfrage (Protokoll `status-v1`): Versionen von REDAXO und PHP,
  AddOns, Ampel der Prüfungen, letzte Sicherung, Fehlerprotokoll (nur Zahlen). Keine Inhalte, keine Personendaten.

Beides ist **standardmäßig aus** und ändert nichts an der Darstellung der Website. English: [README.md](README.md)

## Installation

```bash
composer require klxm/klxm_hub
php bin/console migrate
```

Ohne Packagist (z. B. auf einem Server per `git archive`): Quellen nach `~/src/klxm_hub` legen und als Pfad-Paket
einbinden (siehe unten „Einrichtung auf einem Server“).

## Einrichtung

1. **Im Hub:** Verwalten → Instanz registrieren → System „REDAXO 6“, Adresse der Website, Backend-Pfad (meist `/redaxo`).
   Die Adressen sind vorbelegt:

   | | Adresse |
   |---|---|
   | Redirect-URI | `https://<host>/redaxo/index.php?rex-api-call=klxm_hub&step=callback` |
   | Einstieg | `https://<host>/redaxo/index.php?rex-api-call=klxm_hub&step=start` |
   | Status | `https://<host>/index.php?rex-api-call=klxm_hub_status` |
   | Nach dem Abmelden | `https://<host>/redaxo/index.php` |

   Client-ID und Client-Geheimnis erscheinen **einmal**. Freigaben je Person mit Rolle `admin` oder `redaktion`.
2. **In REDAXO:** AddOns → Agentur Hub → Einstellungen
   - Issuer (z. B. `https://hub.klxm.de/hub`), Client-ID, Client-Geheimnis, Rolle für `redaktion`, „Anmeldung über den
     Anbieter anbieten“ → Speichern. Das AddOn prüft dabei, ob der Hub erreichbar ist (Discovery), sonst bleibt es aus.
   - Der Kasten „Adressen für den Hub“ zeigt die Adressen, die diese Installation erwartet – sie müssen im Hub exakt
     so stehen (Grundlage ist `REX_BASE_URL` bzw. der Host der Anfrage). **`REX_BASE_URL` setzen** (z. B. in
     `.env.local`), sonst hängt die Redirect-URI am Host-Header der Anfrage – die Einstellungen warnen dann.
   - Überwachung: öffentlichen Schlüssel des Hubs (`ed25519:…`, im Hub unter Einstellungen → Agentur Hub) eintragen,
     „Signierte Status-Abfrage erlauben“ → Speichern. Optional einen Ordner mit Sicherungen angeben.
3. **Im Hub:** „Jetzt prüfen“ – die Ampel erscheint.
4. **Notfall-Admin behalten:** mindestens einen lokalen Admin mit Passwort (und Passkey) behalten. Das AddOn warnt,
   wenn keiner da ist.

## Agentur-Konten

- Zuordnung über Issuer + `sub` des Hubs (Tabelle `rex_klxm_hub_identity`), nie über die E-Mail-Adresse.
- Bei der ersten Anmeldung wird ein Konto angelegt (abschaltbar): Login = E-Mail-Adresse (bzw. `hub-…`), **ohne
  Passwort**, Beschreibung „Agentur-Konto (Agentur Hub) – Anmeldung nur über den Anbieter“. Meldet der Hub die
  Adresse als unbestätigt (`email_verified` ≠ true), wird sie weder Login noch E-Mail des Kontos.
- Rolle aus dem Claim `roles`: `admin` → Admin, `redaktion` → die eingestellte Rolle; ohne passende Rolle keine
  Anmeldung. Die Rechte werden bei **jeder** Anmeldung abgeglichen.
- Vorhandene Benutzer werden **nie übernommen** (gleicher Login → Anmeldung abgelehnt). Bekommt ein Agentur-Konto
  lokal ein Passwort, lehnt der Hub-Zugang es ab.
- **Sperren:** AddOns → Agentur Hub → Agentur-Konten → „Sperren“ (oder Status in der Benutzerverwaltung). Laufende
  Sitzungen enden sofort. Löschen über die Benutzerverwaltung – die Zuordnung verschwindet mit.

## Sicherheit

- Authorization Code Flow mit **PKCE S256**, `state` (32 Byte, an die Backend-Sitzung gebunden, einmal verwendbar,
  10 Minuten), `nonce`; Rückkehr mit `iss` nach RFC 9207 (Pflicht, wenn der Hub ihn ankündigt).
- ID-Token nur **RS256**, Schlüssel aus dem JWKS des Hubs (unbekannte `kid` → einmal neu laden, höchstens alle
  60 Sekunden); geprüft werden `iss`, `aud`/`azp`, `exp`/`iat` (±60 s, nicht älter als 10 Minuten), `nonce`,
  `at_hash`, `sub`. `none`, HS* und `crit` werden abgewiesen, RSA-Schlüssel unter 2048 Bit (bitgenau) und mit
  Exponent < 3 bzw. gerade ebenso; `jku`, `x5u` und `jwk` im Kopf werden nie verwendet.
- **Zweiter Faktor:** `amr` muss einen zweiten Faktor belegen (`mfa`, `otp`, `hwk`, …) – Passwort allein reicht nicht.
- Endpunkte des Hubs nur per https (http nur mit `REX_MODE=dev`). Abrufe über den HTTP-Client des Cores, ohne
  Weiterleitungen, höchstens 10 Sekunden und 512 KB.
- **Keine Abrufe ins interne Netz (SSRF):** Discovery, JWKS, Token und Logo gehen nie an private, lokale oder
  reservierte Adressen (127/8, 10/8, 172.16/12, 192.168/16, 169.254/16, 100.64/10, ::1, fc00::/7, fe80::/10, IPv4 in
  IPv6 …). Geprüft wird die aufgelöste Adresse, verbunden wird genau mit ihr (kein DNS-Rebinding). Ausnahmen: Modus
  `dev` oder `KLXM_HUB_ALLOW_PRIVATE_NETWORK=1` (Hub im eigenen Netz, ausgehender Proxy mit privater Adresse).
- **Logo** des Anbieters: nur beim Speichern geladen, als SVG neu aufgebaut (nur Formen, keine Texte, Skripte, Stile,
  Verweise, Ereignisse, fremden Namensräume; nur UTF-8, keine DTD) und bei jeder Ausgabe erneut bereinigt.
- Antworten des Anmelde-Endpunkts mit `Cache-Control: no-store`; der Link auf der Anmeldeseite ist relativ.
- Client-Geheimnis verschlüsselt (libsodium secretbox); der Schlüssel liegt in `var/data/addons/klxm_hub/secret.key`,
  nicht in der Datenbank. Alternativ ohne Speicherung: Umgebungsvariable `KLXM_HUB_CLIENT_SECRET`.
- **Rate-Limit** je IP: Anmeldung 30 Aufrufe bzw. 10 Fehlversuche in 10 Minuten, Status-Abfrage 30 in 10 Minuten.
  IP-Adressen nur als HMAC gespeichert.
- **Protokoll** im Systemprotokoll von REDAXO, eine Zeile je Ereignis ohne Geheimnisse, z. B.
  `klxm_hub: login-denied reason=state ip=…`, `klxm_hub: login-ok user=12 created=1`,
  `klxm_hub: monitor-denied reason=signature`. Hinter einem Proxy die vertrauenswürdigen Proxys in Symfony
  (`Request::setTrustedProxies`) setzen, sonst steht dort die IP des Proxys.

### Status-Abfrage (`status-v1`)

Prüfung in dieser Reihenfolge: aus oder kein Schlüssel → `404`; Rate-Limit → `429`; Format der Kopfzeilen, Zeitfenster
±300 s, Schlüssel-ID, Ed25519-Signatur (Host aus der Anfrage, Pfad mit Query aus `REQUEST_URI`) → `401`; erst nach
gültiger Signatur wird der Nonce gemerkt – 11 Minuten, also länger als das ganze Zeitfenster von 2 × 300 s
(Wiederholung → `401`); dann `200` mit JSON (60 Sekunden
zwischengespeichert, `Cache-Control: no-store`, `X-Robots-Tag: noindex`). Fehlerantworten nennen keine Gründe.

Prüfungen der Ampel: Datenbank, beschreibbare Ordner, ausstehende Migrationen, Modus `dev` auf öffentlicher Adresse,
HTTPS, AddOns als Entwicklungsfassung (im Modus live), OPcache, Notfall-Admin. Das Fehlerprotokoll (24 Stunden) geht
als Feld `log` mit; der Hub bewertet es selbst. `integrity` meldet `unknown` (keine Integritätsprüfung in REDAXO).

## Für Entwickler

Erweiterungspunkte (Klassen, `::class` lädt nichts, wenn das AddOn fehlt):

```php
use Klxm\Hub\ExtensionPoint\HubStatus;
use Klxm\Hub\ExtensionPoint\HubUserMapping;
use Redaxo\Core\ExtensionPoint\Extension;

// Status ergänzen: eigene Prüfungen, Sicherung eines Backup-Werkzeugs
Extension::register(HubStatus::class, static function (HubStatus $ep): array {
    $status = $ep->subject;
    $status['backup']['offsite_at'] = date('c', MyBackup::lastUpload());
    if (MyQueue::stuck()) {
        $ep->warning('Warteschlange hängt');
    }
    return $status;
});

// Rechte eines Agentur-Kontos anpassen (null = Anmeldung ablehnen)
Extension::register(HubUserMapping::class, static function (HubUserMapping $ep): ?array {
    if (in_array('technik', $ep->hubRoles, true)) {
        return ['admin' => false, 'roles' => [3]];
    }
    return $ep->subject;
});
```

Selbsttest ohne Netz: `php bin/console klxm_hub:selftest` (Signatur, PKCE, ID-Token-Prüfungen, Schlüsselwechsel,
Verschlüsselung, Knopf).

### Was der Core (noch) nicht bietet

- **Anmelden eines Benutzers per Code:** Es gibt keine öffentliche Methode wie `BackendLogin::loginUser(User $user)`.
  Das AddOn setzt die Sitzung wie die Passkey-Anmeldung des Cores (`BackendLogin::checkLogin`) und trägt sie über
  `UserSession` (als `@internal` markiert) ein. Vorschlag für den Core: eine solche Methode.
- **Knopf auf der Anmeldeseite:** kein Erweiterungspunkt in `pages/login.php`; der Knopf kommt über `OUTPUT_FILTER`
  in den Fuß des Formulars `#rex-form-login` (fehlt es, bleibt die Seite unverändert).
- **Abmelden beim Hub (RP-initiated Logout):** Die Abmeldung des Cores läuft vor dem Laden der AddOns – noch nicht
  umgesetzt.

## Einrichtung auf einem Server (ohne Packagist)

```bash
# lokal
git archive --format=tar.gz -o klxm_hub.tgz HEAD
scp klxm_hub.tgz user@host:
# auf dem Server (Projekt im Ordner ~/httpdocs)
mkdir -p ~/src/klxm_hub && tar xzf ~/klxm_hub.tgz -C ~/src/klxm_hub
cd ~/httpdocs
composer config repositories.klxm_hub '{"type":"path","url":"../src/klxm_hub","options":{"symlink":false,"versions":{"klxm/klxm_hub":"0.1.3"}}}'
composer require klxm/klxm_hub:0.1.3 --no-dev
php bin/console migrate && php bin/console cache:clear
```

Bei späteren Updates nach dem Auspacken `composer reinstall klxm/klxm_hub` (Pfad-Pakete ohne Symlink erneuert
`composer update` nicht, wenn sich nur der Code ändert), dann `migrate` und `cache:clear`.

## Lizenz

MIT – siehe [LICENSE](LICENSE). KLXM Crossmedia GmbH.
