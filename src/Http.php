<?php

namespace Klxm\Hub;

use Redaxo\Core\Core;

/**
 * Abrufe beim Anbieter (Discovery, JWKS, Token) über den HTTP-Client des Cores (beachtet HTTP(S)_PROXY/NO_PROXY):
 * höchstens 10 Sekunden, keine Weiterleitungen, Antworten bis 512 KB. Für den Selbsttest ersetzt Http::$transport die Abrufe.
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
        $response = Core::getHttpClient()->request($method, $url, $options);

        return ['status' => $response->getStatusCode(), 'body' => $response->getContent(false)];
    }
}
