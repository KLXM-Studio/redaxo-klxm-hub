<?php

namespace Klxm\Hub;

use Redaxo\Core\Core;
use Redaxo\Core\Env;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Abrufe beim Anbieter (Discovery, JWKS, Token, Logo) über den HTTP-Client des Cores (beachtet HTTP(S)_PROXY/NO_PROXY):
 * höchstens 10 Sekunden, keine Weiterleitungen, Antworten bis 512 KB. Für den Selbsttest ersetzt Http::$transport die Abrufe.
 *
 * Schutz vor SSRF: Ziele in privaten, lokalen und reservierten Netzen (127/8, 10/8, 172.16/12, 192.168/16, 169.254/16,
 * 100.64/10, ::1, fc00::/7, fe80::/10, IPv4 in IPv6 …) werden abgewiesen – geprüft wird die aufgelöste Adresse, die
 * Verbindung geht genau an diese (kein DNS-Rebinding). Ausnahmen: Modus „dev“ (Hub im lokalen Netz) bzw. die
 * Umgebungsvariable KLXM_HUB_ALLOW_PRIVATE_NETWORK=1 (z. B. Hub im Firmennetz oder Proxy mit privater Adresse).
 */
final class Http
{
    private const int MAX_BYTES = 524288;

    /** @var null|callable(string $method, string $url, array<string, string> $headers, ?string $body): array{status: int, body: string} */
    public static $transport = null;

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if (null !== self::$transport) {
            return (self::$transport)($method, $url, $headers, $body);
        }
        $options = [
            'headers' => $headers + ['Accept' => 'application/json'],
            'timeout' => 10,
            'max_duration' => 15,
            'max_redirects' => 0,
            'on_progress' => static function (int $dlNow): void {
                if ($dlNow > self::MAX_BYTES) {
                    throw new \RuntimeException('response too large');
                }
            },
        ];
        if (null !== $body) {
            $options['body'] = $body;
        }
        $response = self::client(self::allowPrivateNetwork())->request($method, $url, $options);

        return ['status' => $response->getStatusCode(), 'body' => $response->getContent(false)];
    }

    /** Ziele in privaten Netzen erlaubt? (nur Modus „dev“ oder ausdrücklich per Umgebungsvariable) */
    public static function allowPrivateNetwork(): bool
    {
        return Core::isDevMode() || '1' === Env::get('KLXM_HUB_ALLOW_PRIVATE_NETWORK');
    }

    /** HTTP-Client – ohne Ausnahme mit Sperre für private Netze */
    public static function client(bool $allowPrivate): HttpClientInterface
    {
        $client = Core::getHttpClient();

        return $allowPrivate ? $client : new NoPrivateNetworkHttpClient($client);
    }
}
