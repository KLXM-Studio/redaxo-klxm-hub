<?php

namespace Klxm\Hub;

use Klxm\Hub\Monitoring\Signature;
use Klxm\Hub\Oidc\Client;
use Klxm\Hub\Oidc\Jwk;
use Klxm\Hub\Oidc\Jwt;
use Klxm\Hub\Oidc\OidcException;

/**
 * Selbsttest ohne Netz und ohne Datenbank (`console klxm_hub:selftest`): Status-Signatur (status-v1), PKCE, Ablauf des
 * OIDC-Clients gegen einen nachgebildeten Anbieter (Discovery, JWKS mit Schlüsselwechsel, Token-Endpunkt), alle Prüfungen
 * des ID-Tokens, zweiter Faktor, Rollen, Verschlüsselung des Geheimnisses und der Knopf auf der Anmeldeseite.
 *
 * @internal
 */
final class SelfTest
{
    /** @var list<string> */
    private array $fails = [];
    private int $ok = 0;

    /** @return array{ok: int, fails: list<string>} */
    public static function run(): array
    {
        $t = new self();
        $t->signature();
        $t->pkce();
        $t->oidc();
        $t->misc();

        return ['ok' => $t->ok, 'fails' => $t->fails];
    }

    private function check(string $name, bool $cond): void
    {
        if ($cond) {
            ++$this->ok;
        } else {
            $this->fails[] = $name;
        }
    }

    /** @param callable(): mixed $fn */
    private function throws(string $name, callable $fn, string $reason): void
    {
        try {
            $fn();
            $this->fails[] = $name . ' (keine Ausnahme)';
        } catch (OidcException $e) {
            $this->check($name . ' (' . $e->reason . ')', str_contains($e->reason, $reason));
        }
    }

    private function signature(): void
    {
        $kp = sodium_crypto_sign_keypair();
        $sec = sodium_crypto_sign_secretkey($kp);
        $pub = sodium_crypto_sign_publickey($kp);
        $other = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $host = 'www.example.org';
        $pq = '/index.php?rex-api-call=klxm_hub_status';
        $seen = [];
        $mark = static function (string $kid, string $nonce) use (&$seen): bool {
            if (isset($seen[$kid . $nonce])) {
                return true;
            }
            $seen[$kid . $nonce] = true;

            return false;
        };
        $now = 1_800_000_000;
        $h = Signature::headers($sec, $host, $pq, $now, 'Nonce-0123456789abcdef');
        $this->check('Signatur: Format des Schlüssels', null !== Signature::parseKey(Signature::formatKey($pub)) && null === Signature::parseKey('ed25519:kurz'));
        $this->check('Signatur: Beispielschlüssel der Spezifikation', null !== Signature::parseKey('ed25519:bcxoa7FRJ1bMtECxT18bKTfkJHZANNuiWv0flwFfRVQ'));
        $this->check('Signatur: Schlüssel-ID 16 Zeichen', 16 === \strlen(Signature::keyId($pub)));
        $this->check('Signatur: falsche Signatur → signature', 'signature' === Signature::verify(['x-status-signature' => Jwk::b64u(str_repeat("\1", 64))] + $h, 'GET', $host, $pq, [$pub], $mark, $now));
        $this->check('Signatur: Nonce nach ungültiger Signatur nicht verbraucht', null === Signature::verify($h, 'GET', $host, $pq, [$other, $pub], $mark, $now));
        $this->check('Signatur: Wiederholung → replay', 'replay' === Signature::verify($h, 'GET', $host, $pq, [$pub], $mark, $now));
        $h2 = Signature::headers($sec, $host, $pq, $now, 'Nonce-zweite-Anfrage-1');
        $this->check('Signatur: Zeitfenster +301 s → time', 'time' === Signature::verify($h2, 'GET', $host, $pq, [$pub], $mark, $now + 301));
        $this->check('Signatur: Zeitfenster −301 s → time', 'time' === Signature::verify($h2, 'GET', $host, $pq, [$pub], $mark, $now - 301));
        $this->check('Signatur: unbekannter Schlüssel → key', 'key' === Signature::verify($h2, 'GET', $host, $pq, [$other], $mark, $now));
        $this->check('Signatur: anderer Host → signature', 'signature' === Signature::verify($h2, 'GET', 'evil.example.org', $pq, [$pub], $mark, $now));
        $this->check('Signatur: anderer Pfad → signature', 'signature' === Signature::verify($h2, 'GET', $host, $pq . '&x=1', [$pub], $mark, $now));
        $this->check('Signatur: POST statt GET → signature', 'signature' === Signature::verify($h2, 'POST', $host, $pq, [$pub], $mark, $now));
        $this->check('Signatur: Nonce zu kurz → format', 'format' === Signature::verify(['x-status-nonce' => 'kurz'] + $h2, 'GET', $host, $pq, [$pub], $mark, $now));
        $this->check('Signatur: ohne Kopfzeilen → format', 'format' === Signature::verify([], 'GET', $host, $pq, [$pub], $mark, $now));
        $this->check('Signatur: gültig bei ±300 s', null === Signature::verify($h2, 'GET', $host, $pq, [$pub], $mark, $now + 300));
        $base = Signature::base('get', 'WWW.Example.org:8443', $pq, 1, 'n');
        $this->check('Signatur: Basiszeichenkette (6 Zeilen, Host klein)', "status-v1\nGET\nwww.example.org:8443\n" . $pq . "\n1\nn" === $base);
    }

    private function pkce(): void
    {
        // RFC 7636, Anhang B
        $this->check('PKCE: Testvektor RFC 7636', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM' === Client::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
    }

    private function oidc(): void
    {
        $iss = 'https://hub.example.org/hub';
        $cid = 'hub-0123456789abcdef';
        $secret = 'geheim-' . bin2hex(random_bytes(8));
        $key1 = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $key2 = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $weak = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if (!$key1 || !$key2 || !$weak) {
            $this->fails[] = 'OIDC: RSA-Schlüssel erzeugen';

            return;
        }
        $jwks = ['keys' => [Jwk::fromRsa($key1, 'k1')]];
        $disc = ['issuer' => $iss, 'authorization_endpoint' => $iss . '/authorize', 'token_endpoint' => $iss . '/token', 'jwks_uri' => $iss . '/jwks',
            'code_challenge_methods_supported' => ['S256'], 'authorization_response_iss_parameter_supported' => true];
        $p = ['issuer' => $iss, 'client_id' => $cid, 'client_secret' => $secret, 'auth' => 'client_secret_basic'];
        $tokenResponse = null;   // fn(array $form, array $headers): array
        $calls = [];
        Http::$transport = static function (string $method, string $url, array $headers, ?string $body) use (&$disc, &$jwks, &$tokenResponse, &$calls, $iss): array {
            $calls[] = $method . ' ' . $url;
            if ($url === $iss . '/.well-known/openid-configuration') {
                return ['status' => 200, 'body' => (string) json_encode($disc)];
            }
            if ($url === $iss . '/jwks') {
                return ['status' => 200, 'body' => (string) json_encode($jwks)];
            }
            if ($url === $iss . '/token' && 'POST' === $method) {
                parse_str((string) $body, $form);

                return ($tokenResponse)($form, $headers);
            }

            return ['status' => 404, 'body' => ''];
        };
        Client::resetForTest();
        Client::$baseOverride = 'https://www.example.org/';
        Client::$now = time();

        try {
            $url = Client::begin($p, 'mia@example.org');
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $this->check('OIDC: Weiterleitung zum authorization_endpoint', str_starts_with($url, $iss . '/authorize?'));
            $this->check('OIDC: PKCE S256 + state/nonce ≥ 32 Byte', 'S256' === ($q['code_challenge_method'] ?? '') && \strlen((string) ($q['state'] ?? '')) >= 43 && \strlen((string) ($q['nonce'] ?? '')) >= 43);
            $this->check('OIDC: Redirect-URI wie beim Hub registriert', 'https://www.example.org/redaxo/index.php?rex-api-call=klxm_hub&step=callback' === ($q['redirect_uri'] ?? ''));
            $this->check('OIDC: login_hint durchgereicht', 'mia@example.org' === ($q['login_hint'] ?? ''));
            $pend = Client::takeState((string) $q['state']);
            $this->check('OIDC: state gefunden', null !== $pend);
            $this->check('OIDC: state nur einmal verwendbar', null === Client::takeState((string) $q['state']));
            $this->check('OIDC: fremder state abgewiesen', null === Client::takeState(str_repeat('x', 43)));
            if (null === $pend) {
                return;
            }

            $claims = fn (array $over = []): array => array_filter($over + ['iss' => $iss, 'sub' => 'u-42', 'aud' => $cid, 'azp' => $cid, 'exp' => Client::now() + 300,
                'iat' => Client::now(), 'nonce' => (string) $pend['n'], 'amr' => ['pwd', 'otp'], 'roles' => ['admin'], 'email' => 'mia@example.org'], static fn ($v) => null !== $v);
            $issue = static function (array $c, $key = null, ?string $kid = 'k1', ?string $at = 'access-token-123', array $header = []) use ($key1): array {
                $c['at_hash'] ??= null !== $at ? Jwt::halfHash($at) : null;

                return ['status' => 200, 'body' => (string) json_encode(array_filter(['access_token' => $at, 'token_type' => 'Bearer', 'id_token' => Jwt::sign(array_filter($c, static fn ($v) => null !== $v), $key ?? $key1, $kid, $header)]))];
            };
            $seenForm = [];
            $seenHeaders = [];
            $tokenResponse = static function (array $form, array $headers) use (&$seenForm, &$seenHeaders, $issue, $claims): array {
                $seenForm = $form;
                $seenHeaders = $headers;

                return $issue($claims());
            };
            $res = Client::finish($p, $pend, 'code-abc');
            $this->check('OIDC: Code eingelöst, ID-Token gültig', 'u-42' === ($res['claims']['sub'] ?? null));
            $this->check('OIDC: code_verifier gesendet', ($seenForm['code_verifier'] ?? '') === $pend['v'] && Client::challenge((string) $pend['v']) === $q['code_challenge']);
            $this->check('OIDC: client_secret_basic (Kopfzeile, nicht im Formular)', str_starts_with((string) ($seenHeaders['Authorization'] ?? ''), 'Basic ') && !isset($seenForm['client_secret']));
            $this->check('OIDC: zweiter Faktor belegt (pwd+otp)', Client::strongAmr($res['claims']));
            $this->check('OIDC: nur Passwort reicht nicht', !Client::strongAmr(['amr' => ['pwd']]) && !Client::strongAmr([]));
            $this->check('OIDC: Rollen als Liste und Text', ['admin'] === Client::roles(['roles' => ['Admin']]) && ['redaktion', 'x'] === Client::roles(['roles' => 'redaktion x']));
            $this->check('OIDC: Rolle admin → Admin', ['admin' => true, 'roles' => []] === Accounts::mapping(['roles' => ['admin']], 0));
            $this->check('OIDC: ohne passende Rolle → keine Anmeldung', null === Accounts::mapping(['roles' => ['gast']], 0) && null === Accounts::mapping(['roles' => ['redaktion']], 0));

            $finish = function (callable $resp) use ($p, $pend, &$tokenResponse): void {
                $tokenResponse = $resp;
                Client::finish($p, $pend, 'code-abc');
            };
            $bad = static fn (array $c, ...$a): callable => static fn (): array => $issue($c, ...$a);
            $this->throws('ID-Token: falscher iss', fn () => $finish($bad($claims(['iss' => 'https://evil.example.org/hub']))), 'iss');
            $this->throws('ID-Token: falsche aud', fn () => $finish($bad($claims(['aud' => 'anderer-client']))), 'aud');
            $this->throws('ID-Token: mehrere aud ohne passendes azp', fn () => $finish($bad($claims(['aud' => [$cid, 'x'], 'azp' => 'x']))), 'azp');
            $this->throws('ID-Token: abgelaufen', fn () => $finish($bad($claims(['exp' => Client::now() - 61]))), 'exp');
            $this->throws('ID-Token: iat zu alt', fn () => $finish($bad($claims(['iat' => Client::now() - 601]))), 'iat');
            $this->throws('ID-Token: iat in der Zukunft', fn () => $finish($bad($claims(['iat' => Client::now() + 61]))), 'iat');
            $this->throws('ID-Token: falsche nonce', fn () => $finish($bad($claims(['nonce' => 'andere']))), 'nonce');
            $this->throws('ID-Token: ohne sub', fn () => $finish($bad(array_diff_key($claims(), ['sub' => 1]))), 'sub');
            $this->throws('ID-Token: at_hash passt nicht', fn () => $finish($bad($claims(['at_hash' => 'AAAAAAAAAAAAAAAAAAAAAA']))), 'at_hash');
            $this->throws('ID-Token: at_hash fehlt bei Access-Token', fn () => $finish(static fn (): array => ['status' => 200, 'body' => (string) json_encode(['access_token' => 'a', 'token_type' => 'Bearer',
                'id_token' => Jwt::sign($claims(), $key1, 'k1')])]), 'at_hash');
            $this->throws('ID-Token: fremder Schlüssel (Signatur)', fn () => $finish($bad($claims(), $key2, 'k1')), 'signature');
            $this->throws('ID-Token: alg none', fn () => $finish(static fn (): array => ['status' => 200, 'body' => (string) json_encode(['token_type' => 'Bearer',
                'id_token' => Jwk::b64u('{"alg":"none"}') . '.' . Jwk::b64u((string) json_encode($claims())) . '.'])]), 'id_token');
            $this->throws('ID-Token: HS256 mit öffentlichem Schlüssel', function () use ($finish, $claims, $jwks): void {
                $in = Jwk::b64u('{"alg":"HS256","typ":"JWT","kid":"k1"}') . '.' . Jwk::b64u((string) json_encode($claims()));
                $finish(static fn (): array => ['status' => 200, 'body' => (string) json_encode(['token_type' => 'Bearer',
                    'id_token' => $in . '.' . Jwk::b64u(hash_hmac('sha256', $in, (string) json_encode($jwks['keys'][0]), true))])]);
            }, 'alg');
            $this->throws('ID-Token: crit im Kopf', fn () => $finish($bad($claims(), null, 'k1', 'access-token-123', ['crit' => ['exp']])), 'crit');
            $this->throws('ID-Token: zu kurzer RSA-Schlüssel (1024 Bit)', function () use ($finish, $claims, $issue, $weak, &$jwks): void {
                $jwks = ['keys' => [Jwk::fromRsa($weak, 'weak')]];
                $finish(static fn (): array => $issue($claims(), $weak, 'weak'));
            }, 'signature');
            // Schlüsselwechsel beim Anbieter: neue kid → JWKS einmal neu laden
            $jwks = ['keys' => [Jwk::fromRsa($key1, 'k1')]];
            Client::jwks($iss . '/jwks');
            $jwks = ['keys' => [Jwk::fromRsa($key2, 'k2')]];
            $tokenResponse = static fn (): array => $issue($claims(), $key2, 'k2');
            $res = Client::finish($p, $pend, 'code-abc');
            $this->check('ID-Token: Schlüsselwechsel (unbekannte kid → JWKS neu)', 'u-42' === ($res['claims']['sub'] ?? null));
            $this->throws('Token-Endpunkt: Code abgelehnt (invalid_grant)', fn () => $finish(static fn (): array => ['status' => 400, 'body' => '{"error":"invalid_grant"}']), 'invalid_grant');
            $this->throws('Token-Endpunkt: ohne ID-Token', fn () => $finish(static fn (): array => ['status' => 200, 'body' => '{"access_token":"a","token_type":"Bearer"}']), 'no_id_token');

            // Discovery
            Client::resetForTest();
            $disc['issuer'] = 'https://anderer.example.org/hub';
            $this->throws('Discovery: anderer Issuer', fn () => Client::discovery($iss), 'discovery:issuer');
            Client::resetForTest();
            $disc['issuer'] = $iss;
            $disc['code_challenge_methods_supported'] = ['plain'];
            $this->throws('Discovery: ohne PKCE S256', fn () => Client::discovery($iss), 'discovery:pkce');
            Client::resetForTest();
            $disc['code_challenge_methods_supported'] = ['S256'];
            $disc['token_endpoint'] = 'javascript:alert(1)';
            $this->throws('Discovery: unsicherer Endpunkt', fn () => Client::discovery($iss), 'discovery:token_endpoint');
        } finally {
            Http::$transport = null;
            Client::$baseOverride = null;
            Client::$now = null;
            Client::$pendingMemory = null;
        }
    }

    private function misc(): void
    {
        Secret::$testKey = sodium_crypto_secretbox_keygen();
        try {
            $sealed = Secret::encrypt('s3cr3t-Wert');
            $this->check('Geheimnis: verschlüsselt, nicht im Klartext', str_starts_with($sealed, 'v1:') && !str_contains($sealed, 's3cr3t'));
            $this->check('Geheimnis: entschlüsselt', 's3cr3t-Wert' === Secret::decrypt($sealed));
            $this->check('Geheimnis: verändert → leer', '' === Secret::decrypt(substr($sealed, 0, -4) . 'AAAA'));
            Secret::$testKey = sodium_crypto_secretbox_keygen();
            $this->check('Geheimnis: anderer Schlüssel → leer', '' === Secret::decrypt($sealed));
        } finally {
            Secret::$testKey = null;
        }
        Settings::$override = ['oidc' => ['enabled' => true, 'name' => 'Agentur Hub', 'issuer' => 'https://hub.example.org/hub', 'client_id' => 'c', 'client_secret' => 's']];
        Client::$baseOverride = 'https://www.example.org/';
        try {
            $html = '<form id="rex-form-login" action="index.php"><section class="rex-section"><footer>x</footer></section></form>';
            $out = HubAddon::injectButton($html);
            $this->check('Anmeldeseite: Knopf im Formular', str_contains($out, 'klxm-hub-login-link') && strpos($out, 'klxm-hub-login') < strpos($out, '</section>'));
            $this->check('Anmeldeseite: Knopf führt zum Einstieg', str_contains($out, 'rex-api-call=klxm_hub&amp;step=start'));
            $clean = Logo::clean('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8" onload="x()"><script>x()</script><style>*{}</style><circle cx="4" cy="4" r="3" fill="url(#a)"/><path d="M0 0h8" stroke="currentColor"/><image href="https://evil.example/x.png"/></svg>') ?? '';
            $this->check('Logo: bereinigt (kein Skript, Stil, Ereignis, Verweis)', '' !== $clean && !preg_match('~script|style|onload|image|url\\(~i', $clean) && str_contains($clean, '<path') && str_contains($clean, 'aria-hidden="true"'));
            $this->check('Logo: DOCTYPE/ENTITY abgewiesen', null === Logo::clean('<!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg"/>'));
            $this->check('Anmeldeseite: ohne Formular unverändert', '<p>x</p>' === HubAddon::injectButton('<p>x</p>'));
            $this->check('Login: E-Mail bzw. hub-…', 'mia@example.org' === Accounts::login('i', 's', 'mia@example.org') && str_starts_with(Accounts::login('i', 's', str_repeat('a', 60) . '@example.org'), 'hub-'));
        } finally {
            Settings::$override = null;
            Client::$baseOverride = null;
        }
    }
}
