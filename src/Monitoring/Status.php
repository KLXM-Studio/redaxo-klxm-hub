<?php

namespace Klxm\Hub\Monitoring;

use Klxm\Hub\ExtensionPoint\HubStatus;
use Klxm\Hub\HubAddon;
use Klxm\Hub\Settings;
use Redaxo\Core\Addon\Addon;
use Redaxo\Core\Core;
use Redaxo\Core\Database\Sql;
use Redaxo\Core\ExtensionPoint\Extension;
use Redaxo\Core\Filesystem\Dir;
use Redaxo\Core\Filesystem\File;
use Redaxo\Core\Filesystem\Path;
use Redaxo\Core\Filesystem\Url;
use Redaxo\Core\Http\Request;
use Redaxo\Core\Log\LogFile;
use Redaxo\Core\Log\Logger;
use Redaxo\Core\Migration\Migrator;
use Redaxo\Core\Mode;

/**
 * Inhalt der Status-Antwort (status-v1, system „redaxo6“): Versionen von REDAXO und PHP, AddOns mit Versionen,
 * Ampel der Prüfungen mit Meldungen, letzte Sicherung – 60 Sekunden zwischengespeichert, nonce wird je Anfrage angehängt.
 * Keine Inhalte, keine Konten, keine Personendaten, keine Geheimnisse, keine Serverpfade.
 *
 * Prüfungen (Fehler = rot, Hinweis = gelb):
 *   Fehler   Datenbank nicht erreichbar · Ordner (media, assets, cache, data) nicht beschreibbar
 *   Hinweis  ausstehende Migrationen · Modus „dev“ auf einer öffentlichen Website · Verwaltung ohne HTTPS ·
 *            AddOns als Entwicklungsfassung (dev-…) im Modus live · Fehler im Systemprotokoll (24 Stunden) ·
 *            OPcache aus · Anmeldung über den Hub an, aber kein lokaler Admin mit Passwort (Notfall-Zugang)
 * Eigene Prüfungen und Sicherungen anderer Werkzeuge über {@see HubStatus}.
 */
final class Status
{
    private const int CACHE_TTL = 60;

    /** @return array<string, mixed> */
    public static function collect(string $nonce): array
    {
        $file = Path::addonCache(HubAddon::NAME, 'status.json');
        $base = null;
        if (is_file($file) && filemtime($file) > time() - self::CACHE_TTL) {
            $base = json_decode((string) File::get($file), true);
        }
        if (!\is_array($base)) {
            $base = self::build();
            File::put($file, (string) json_encode($base, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return $base + ['nonce' => $nonce];
    }

    private static function iso(?int $t): ?string
    {
        return $t ? date('c', $t) : null;
    }

    /** @return array<string, mixed> */
    public static function build(): array
    {
        /** @var list<array{level: string, text: string}> $msgs */
        $msgs = [];
        foreach (self::checks() as [$level, $text]) {
            $msgs[] = ['level' => $level, 'text' => mb_strimwidth($text, 0, 200, '…')];
        }

        $components = [];
        foreach (Addon::getAll() as $addon) {
            $components[] = ['type' => 'addon', 'name' => $addon->name, 'version' => $addon->getVersion(), 'active' => true];
        }

        $siteUrl = null;
        try {
            $siteUrl = Url::absoluteBase();
        } catch (\Throwable) {
        }

        $status = [
            'protocol' => Signature::PROTOCOL,
            'system' => 'redaxo6',
            'system_version' => Core::getVersion(),
            'php' => PHP_VERSION,
            'generated_at' => date('c'),
            'site' => ['key' => 'default', 'url' => $siteUrl],
            'components' => $components,
            'health' => [],
            'backup' => ['last_at' => self::iso(self::lastBackup()), 'offsite_at' => null],
            'integrity' => ['status' => 'unknown', 'checked_at' => null],
            'ssl' => ['expires_at' => null],   // ermittelt der Hub selbst aus der TLS-Verbindung
        ];
        if (Addon::exists('fluid')) {
            $status['theme'] = ['name' => 'fluid', 'version' => (string) Addon::get('fluid')?->getVersion()];
        }

        try {
            $ep = new HubStatus($status);
            $status = (array) Extension::dispatch($ep);
            foreach ($ep->messages as $m) {
                $msgs[] = ['level' => 'error' === $m['level'] ? 'error' : 'warning', 'text' => mb_strimwidth($m['text'], 0, 200, '…')];
            }
        } catch (\Throwable $e) {
            Logger::logException($e);
            $msgs[] = ['level' => 'error', 'text' => 'status extension failed'];
        }

        $err = \count(array_filter($msgs, static fn (array $m): bool => 'error' === $m['level']));
        $warn = \count($msgs) - $err;
        $status['health'] = ['status' => $err ? 'red' : ($warn ? 'yellow' : 'green'), 'errors' => $err, 'warnings' => $warn, 'messages' => \array_slice($msgs, 0, 50)];
        $status['protocol'] = Signature::PROTOCOL;
        $status['system'] = 'redaxo6';
        unset($status['nonce']);

        return $status;
    }

    /** @return list<array{0: 'error'|'warning', 1: string}> */
    public static function checks(): array
    {
        $out = [];
        $live = Mode::Dev !== Core::getMode();
        try {
            Sql::factory()->setQuery('SELECT 1');
        } catch (\Throwable) {
            $out[] = ['error', HubAddon::t('check_db')];
        }
        $notWritable = 0;
        foreach ([Path::media(), Path::assets(), Path::cache(), Path::data()] as $dir) {
            if (!is_dir($dir) || !Dir::isWritable($dir)) {
                ++$notWritable;
            }
        }
        if ($notWritable) {
            $out[] = ['error', HubAddon::t('check_writable', $notWritable)];
        }
        try {
            $pending = \count(Migrator::getPending());
            if ($pending) {
                $out[] = ['warning', HubAddon::t('check_migrations', $pending)];
            }
        } catch (\Throwable) {
            $out[] = ['warning', HubAddon::t('check_migrations_unknown')];
        }
        if (!$live && self::publicHost()) {
            $out[] = ['warning', HubAddon::t('check_dev_mode')];
        }
        if ($live && Core::hasRequest() && !Request::isHttps()) {
            $out[] = ['warning', HubAddon::t('check_https')];
        }
        if ($live) {
            $dev = [];
            foreach (Addon::getAll() as $addon) {
                if (str_starts_with($addon->getVersion(), 'dev-') || str_ends_with($addon->getVersion(), '-dev')) {
                    $dev[] = $addon->name;
                }
            }
            if ($dev) {
                $out[] = ['warning', HubAddon::t('check_dev_addons', implode(', ', \array_slice($dev, 0, 10)))];
            }
        }
        if (!(\function_exists('opcache_get_status') && \ini_get('opcache.enable')) && $live) {
            $out[] = ['warning', HubAddon::t('check_opcache')];
        }
        $errors = self::logErrors(86400);
        if ($errors > 0) {
            $out[] = ['warning', HubAddon::t('check_log', $errors)];
        }
        if (Settings::oidc()['enabled'] && 0 === self::localAdmins()) {
            $out[] = ['warning', HubAddon::t('check_no_local_admin')];
        }

        return $out;
    }

    /** Öffentliche Adresse? (nicht localhost/.localhost/.test/private Netze) */
    private static function publicHost(): bool
    {
        if (!Core::hasRequest()) {
            return false;
        }
        $host = strtolower(Core::getRequest()->getHost());
        if ('localhost' === $host || str_ends_with($host, '.localhost') || str_ends_with($host, '.test') || str_ends_with($host, '.local')
            || 'host.docker.internal' === $host) {
            return false;
        }

        return !filter_var($host, FILTER_VALIDATE_IP) || false !== filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /** Einträge im Systemprotokoll der letzten $window Sekunden, die keine Hinweise sind (höchstens 2000 gelesen) */
    public static function logErrors(int $window): int
    {
        $path = Logger::getPath();
        if (!is_file($path)) {
            return 0;
        }
        $n = 0;
        $read = 0;
        try {
            foreach (LogFile::factory($path) as $entry) {
                if ($entry->getTimestamp() < time() - $window || ++$read > 2000) {
                    break;
                }
                $level = strtolower((string) ($entry->getData()[0] ?? ''));
                $msg = (string) ($entry->getData()[1] ?? '');
                if (\in_array($level, ['info', 'notice', 'debug', 'deprecated', 'warning'], true) || str_starts_with($msg, 'klxm_hub:')) {
                    continue;
                }
                ++$n;
            }
        } catch (\Throwable) {
        }

        return $n;
    }

    /** Aktive Admins mit Passwort (Notfall-Zugang ohne Hub) */
    public static function localAdmins(): int
    {
        try {
            $sql = Sql::factory();
            $sql->setQuery('SELECT COUNT(*) AS n FROM rex_user WHERE admin = 1 AND status = 1 AND password IS NOT NULL AND password <> ""');

            return (int) $sql->getValue('n');
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Zeit der neuesten Sicherung: Dateien (*.sql, *.sql.gz, *.tar.gz, *.tgz, *.zip) im eingestellten Ordner
     * (Einstellungen → Überwachung; absolut oder relativ zum Projektordner) bzw. in var/data/addons/backup und
     * var/data/core/backup. Andere Werkzeuge melden ihre Sicherung über {@see HubStatus}.
     */
    public static function lastBackup(): ?int
    {
        $dirs = [Path::addonData('backup'), Path::coreData('backup')];
        $own = Settings::monitor()['backup_dir'];
        if ('' !== $own) {
            array_unshift($dirs, str_starts_with($own, '/') ? $own : Path::base($own));
        }
        $latest = 0;
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            // eine Ebene tief (z. B. backup-deploy-2026…/db.sql.gz)
            foreach ([...(glob(rtrim($dir, '/') . '/*') ?: []), ...(glob(rtrim($dir, '/') . '/*/*') ?: [])] as $f) {
                if (is_file($f) && preg_match('~\.(sql|sql\.gz|tar\.gz|tgz|zip)$~i', $f)) {
                    $latest = max($latest, (int) @filemtime($f));
                }
            }
        }

        return $latest ?: null;
    }
}
