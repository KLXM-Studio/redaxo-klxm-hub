<?php

namespace Klxm\Hub;

use Klxm\Hub\Api\StatusApi;
use Klxm\Hub\Oidc\Client;
use Redaxo\Core\Core;

/**
 * Öffentliche Selbstauskunft der Installation (Protokoll klxm-instance-v1, wie KLXM Studio `Core\InstanceInfo`) – damit
 * der Agentur Hub die Adressen für Anmeldung und Überwachung selbst ermitteln kann („Adressen ermitteln“):
 *
 *   GET /.well-known/klxm-studio                     wenn die Anfrage index.php erreicht (Umschreibregel des Frontends)
 *   GET /index.php?rex-api-call=klxm_hub_instance    gleicher Inhalt, ohne Umschreibregel
 *
 * 404, solange weder die Anmeldung (Issuer oder Client-ID eingetragen) noch die Überwachung eingerichtet ist. Inhalt:
 * Name der Website, Redirect-URI, Einstieg, Adresse nach dem Abmelden, Status-Adresse. Keine Versionen, keine
 * Personendaten, keine Inhalte, keine Schlüssel.
 */
final class InstanceInfo
{
    public const string SPEC = 'klxm-instance-v1';
    public const string PATH = '/.well-known/klxm-studio';
    public const string SYSTEM = 'redaxo6';

    /** @return array<string, mixed>|null */
    public static function build(): ?array
    {
        $o = Settings::oidc();
        $m = Settings::monitor();
        $oidc = '' !== $o['issuer'] || '' !== $o['client_id'];
        $monitor = $m['enabled'] && $m['keys'];
        if (!$oidc && !$monitor) {
            return null;
        }
        $statusUrl = $monitor ? Client::statusUri() : null;

        return [
            'spec' => self::SPEC,
            'system' => self::SYSTEM,
            'name' => self::name(),
            'login' => [
                'redirect_uri' => Client::redirectUri(),
                'initiate_login_uri' => Client::startUri(),
                'post_logout_redirect_uri' => Client::logoutUri(),
                'configured' => $oidc,
            ],
            'monitoring' => ['status_url' => $statusUrl, 'sites' => $statusUrl ? [['key' => 'default', 'url' => Client::siteUrl(), 'status_url' => $statusUrl]] : []],
        ];
    }

    /** Name der Installation (REX_INSTANCE_NAME), sonst Host der Website */
    public static function name(): string
    {
        $name = '';
        try {
            $name = trim(strip_tags((string) Core::getProject()->instanceName));
        } catch (\Throwable) {
        }

        return mb_substr($name, 0, 120) ?: (string) parse_url(Client::siteUrl(), PHP_URL_HOST);
    }

    /** Frontend: /.well-known/klxm-studio beantworten (aus boot(), bevor andere AddOns die Adresse auflösen) */
    public static function serveWellKnown(): void
    {
        $path = (string) parse_url((string) Core::getRequest()->server->get('REQUEST_URI', ''), PHP_URL_PATH);
        if (self::PATH !== $path) {
            return;
        }
        self::send();
    }

    public static function send(): never
    {
        $info = self::build();
        StatusApi::send($info ?? ['error' => 'not_found'], null !== $info ? '200 OK' : '404 Not Found');
    }
}
