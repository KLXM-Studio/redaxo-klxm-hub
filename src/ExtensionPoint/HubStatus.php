<?php

namespace Klxm\Hub\ExtensionPoint;

use Redaxo\Core\ExtensionPoint\ExtensionPoint;

/**
 * Antwort der Status-Abfrage (status-v1) ergänzen, bevor sie zwischengespeichert wird (60 Sekunden) – z. B. eigene
 * Prüfungen als Meldungen, die letzte Sicherung eines Backup-Werkzeugs oder die Integrität:
 *
 *     Extension::register(HubStatus::class, static function (HubStatus $ep): array {
 *         $status = $ep->subject;
 *         $status['backup']['offsite_at'] = date('c', MyBackup::lastUpload());
 *         $ep->warning('Speicherplatz fast voll');      // zählt in health mit
 *         return $status;
 *     });
 *
 * Keine Inhalte, keine Konten, keine Personendaten, keine Geheimnisse, keine Serverpfade. `::class` lädt die Klasse
 * nicht – die Anmeldung schadet also nicht, wenn klxm_hub fehlt.
 *
 * @extends ExtensionPoint<array<string, mixed>>
 */
final class HubStatus extends ExtensionPoint
{
    /** @var list<array{level: string, text: string}> */
    public array $messages = [];

    /** @param array<string, mixed> $status */
    public function __construct(array $status)
    {
        parent::__construct(self::class, $status);
    }

    public function error(string $text): void
    {
        $this->messages[] = ['level' => 'error', 'text' => $text];
    }

    public function warning(string $text): void
    {
        $this->messages[] = ['level' => 'warning', 'text' => $text];
    }
}
