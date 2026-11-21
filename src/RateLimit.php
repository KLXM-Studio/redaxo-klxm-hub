<?php

namespace Klxm\Hub;

use Redaxo\Core\Core;
use Redaxo\Core\Database\Sql;

/**
 * Zähler mit Zeitfenster in der Tabelle rex_klxm_hub_hit (Schlüssel, Zeit) – für das Rate-Limit je IP und für die
 * schon gesehenen Nonces der Status-Abfrage. IP-Adressen werden nur als HMAC gespeichert.
 */
final class RateLimit
{
    public const string TABLE = 'rex_klxm_hub_hit';
    private const int KEEP = 86400;

    /** @var array<string, list<int>>|null Speicher ohne Datenbank (Selbsttest) */
    public static ?array $memory = null;

    public static function count(string $key, int $window): int
    {
        if (null !== self::$memory) {
            return \count(array_filter(self::$memory[$key] ?? [], static fn (int $t): bool => $t > time() - $window));
        }
        $sql = Sql::factory();
        $sql->setQuery('SELECT COUNT(*) AS n FROM ' . self::TABLE . ' WHERE k = ? AND t > ?', [self::hash($key), time() - $window]);

        return (int) $sql->getValue('n');
    }

    public static function hit(string $key): void
    {
        if (null !== self::$memory) {
            self::$memory[$key][] = time();

            return;
        }
        Sql::factory()->setTable(self::TABLE)->setValue('k', self::hash($key))->setValue('t', time())->insert();
        // gelegentlich aufräumen
        if (1 === random_int(1, 50)) {
            Sql::factory()->setQuery('DELETE FROM ' . self::TABLE . ' WHERE t < ?', [time() - self::KEEP]);
        }
    }

    public static function tooMany(string $key, int $max, int $window): bool
    {
        return self::count($key, $window) >= $max;
    }

    /** Schlüssel für eine IP-Adresse (nicht im Klartext gespeichert) */
    public static function ip(string $prefix): string
    {
        $ip = Core::hasRequest() ? (string) Core::getRequest()->getClientIp() : '';

        return $prefix . ':ip:' . hash_hmac('sha256', $ip, self::pepper());
    }

    private static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    private static function pepper(): string
    {
        try {
            return Core::getProject()->instanceId;
        } catch (\Throwable) {
            return 'klxm_hub';
        }
    }
}
