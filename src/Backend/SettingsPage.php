<?php

namespace Klxm\Hub\Backend;

use Klxm\Hub\Enroll;
use Klxm\Hub\HubAddon;
use Klxm\Hub\Log;
use Klxm\Hub\Monitoring\Status;
use Klxm\Hub\Oidc\Client;
use Klxm\Hub\Oidc\OidcException;
use Klxm\Hub\Settings;
use Redaxo\Core\Core;
use Redaxo\Core\Database\Sql;
use Redaxo\Core\Http\Request;
use Redaxo\Core\Security\CsrfToken;
use Redaxo\Core\View\Component\Section;
use Redaxo\Core\View\Html;
use Redaxo\Core\View\Message;

use function Redaxo\Core\View\escape;

/**
 * Backend → AddOns → Agentur Hub → Einstellungen: Anmeldung über den Hub und Überwachung (beides Standard aus).
 *
 * @internal
 */
final class SettingsPage
{
    private const string CSRF = 'klxm_hub_settings';

    /** Eben erzeugter Code (nur in dieser Antwort sichtbar) */
    private static ?string $code = null;

    public static function run(): string
    {
        $out = self::action();
        $s = Settings::oidc();
        $m = Settings::monitor();
        $t = HubAddon::t(...);
        $csrf = CsrfToken::factory(self::CSRF)->getHiddenField();

        $field = static fn (string $label, string $control, string $note = '', string $for = ''): string => '<div class="rex-field"><'
            . ('' !== $for ? 'label for="' . $for . '"' : 'span') . ' class="rex-field-label">' . $label . '</' . ('' !== $for ? 'label' : 'span') . '>'
            . '<div class="rex-field-control">' . $control . ('' !== $note ? '<p class="rex-field-note">' . $note . '</p>' : '') . '</div></div>';
        $check = static fn (string $name, bool $on, string $label): string => '<div class="rex-check"><label><input type="checkbox" name="' . $name . '" value="1"' . ($on ? ' checked' : '') . '> ' . $label . '</label></div>';
        $input = static fn (string $id, string $name, string $value, string $type = 'text', string $extra = ''): string => '<input class="rex-input" type="' . $type . '" id="' . $id . '" name="' . $name . '" value="' . escape($value) . '"' . $extra . '>';

        // ---------------------------------------------------------------- Mit dem Hub verbinden (Einmal-Code)
        $pend = Enroll::pending();
        $done = Enroll::done();
        $body = '<p>' . escape($t('enroll_note')) . '</p>'
            . (null !== $done ? '<p class="klxm-hub-muted">' . escape($t('enroll_done', (string) $done['issuer'], date('d.m.Y H:i', (int) $done['at']))) . '</p>' : '')
            . (null !== self::$code ? $field($t('enroll_code'), '<textarea class="rex-input klxm-hub-keys" id="kh-code" rows="3" readonly spellcheck="false">' . escape(self::$code) . '</textarea>', $t('enroll_code_note'), 'kh-code')
                : (null !== $pend ? '<p class="klxm-hub-muted">' . escape($t('enroll_pending', date('H:i', $pend['expires']))) . '</p>' : ''))
            . '<form method="post">' . $csrf . '<input type="hidden" name="klxm_hub_do" value="enroll"><div class="rex-button-toolbar">'
            . '<button type="submit" class="rex-button" data-variant="primary">' . escape(null !== $pend || null !== self::$code ? $t('enroll_new') : $t('enroll_create')) . '</button>'
            . (null !== $pend || null !== self::$code ? '<button type="submit" class="rex-button" name="klxm_hub_cancel" value="1">' . escape($t('enroll_cancel')) . '</button>' : '')
            . '</div></form>';
        $out .= new Section(title: $t('section_enroll'), body: Html::raw($body))->render();

        // ---------------------------------------------------------------- Anmeldung
        $roles = '<option value="0">' . escape($t('role_none')) . '</option>';
        foreach (Sql::factory()->getArray('SELECT id, name FROM rex_user_role ORDER BY name') as $r) {
            $roles .= '<option value="' . (int) $r['id'] . '"' . ((int) $r['id'] === $s['role_redaktion'] ? ' selected' : '') . '>' . escape((string) $r['name']) . '</option>';
        }
        $secretNote = $s['secret_from_env'] ? $t('secret_env') : ('' !== $s['client_secret'] ? $t('secret_saved') : $t('secret_missing'));
        $body = '<form method="post">' . $csrf . '<input type="hidden" name="klxm_hub_do" value="oidc">'
            . $field($t('oidc_enabled'), $check('oidc[enabled]', $s['enabled'], $t('oidc_enabled_label')), $t('oidc_enabled_note'))
            . $field($t('oidc_name'), $input('kh-name', 'oidc[name]', $s['name'], 'text', ' maxlength="60"'), $t('oidc_name_note'), 'kh-name')
            . $field($t('oidc_issuer'), $input('kh-iss', 'oidc[issuer]', $s['issuer'], 'url', ' placeholder="https://hub.example.org/hub" spellcheck="false"'), $t('oidc_issuer_note'), 'kh-iss')
            . $field($t('oidc_client_id'), $input('kh-cid', 'oidc[client_id]', $s['client_id'], 'text', ' spellcheck="false" autocomplete="off"'), '', 'kh-cid')
            . $field($t('oidc_client_secret'), $input('kh-sec', 'oidc[client_secret]', '', 'password', ' autocomplete="new-password"' . ($s['secret_from_env'] ? ' disabled' : '')), escape($secretNote), 'kh-sec')
            . $field($t('oidc_auth'), '<select class="rex-input" id="kh-auth" name="oidc[auth]">'
                . '<option value="client_secret_basic"' . ('client_secret_basic' === $s['auth'] ? ' selected' : '') . '>client_secret_basic (' . escape($t('recommended')) . ')</option>'
                . '<option value="client_secret_post"' . ('client_secret_post' === $s['auth'] ? ' selected' : '') . '>client_secret_post</option></select>', '', 'kh-auth')
            . $field($t('oidc_roles'), '<p class="klxm-hub-map"><code>admin</code> → ' . escape($t('role_admin')) . '</p>'
                . '<label class="klxm-hub-map" for="kh-role"><code>redaktion</code> → </label><select class="rex-input klxm-hub-role" id="kh-role" name="oidc[role_redaktion]">' . $roles . '</select>', $t('oidc_roles_note'))
            . $field($t('oidc_jit'), $check('oidc[jit]', $s['jit'], $t('oidc_jit_label')), $t('oidc_jit_note'))
            . '<div class="rex-button-toolbar"><button type="submit" class="rex-button" data-variant="primary">' . escape($t('save')) . '</button>'
            . ('' !== $s['client_secret'] && !$s['secret_from_env'] ? '<button type="submit" class="rex-button" name="klxm_hub_forget" value="1" data-confirm="' . escape($t('secret_forget_confirm')) . '">' . escape($t('secret_forget')) . '</button>' : '')
            . '</div></form>';
        $out .= new Section(title: $t('section_oidc'), body: Html::raw($body))->render();

        // ---------------------------------------------------------------- Adressen für den Hub
        $addr = [
            $t('addr_redirect') => Client::redirectUri(),
            $t('addr_start') => Client::startUri(),
            $t('addr_status') => Client::statusUri(),
            $t('addr_logout') => Client::logoutUri(),
            $t('addr_instance') => Client::siteUrl() . 'index.php?rex-api-call=klxm_hub_instance',
        ];
        $rows = '';
        foreach ($addr as $label => $url) {
            $rows .= '<tr><th scope="row">' . escape($label) . '</th><td><code class="klxm-hub-url">' . escape((string) $url) . '</code></td></tr>';
        }
        // ohne REX_BASE_URL hängen Redirect-URI und Einstieg am Host der Anfrage (Host-Header) – fest einstellen
        $baseWarn = null === Core::getProject()->baseUrl ? Message::warning(escape($t('warn_no_base_url'))) : '';
        $out .= new Section(title: $t('section_addresses'), body: Html::raw('<p>' . escape($t('addresses_note')) . '</p>' . $baseWarn
            . '<p class="klxm-hub-muted">' . escape($t('addresses_instance_note')) . '</p>'),
            content: Html::raw('<table class="rex-table klxm-hub-addresses"><tbody>' . $rows . '</tbody></table>'))->render();

        // ---------------------------------------------------------------- Überwachung
        $keys = '';
        foreach ($m['keys'] as $k) {
            $keys .= ('' !== $k['label'] ? $k['label'] . ' ' : '') . $k['key'] . "\n";
        }
        $body = '<form method="post">' . $csrf . '<input type="hidden" name="klxm_hub_do" value="monitor">'
            . $field($t('monitor_enabled'), $check('monitor[enabled]', $m['enabled'], $t('monitor_enabled_label')), $t('monitor_enabled_note'))
            . $field($t('monitor_keys'), '<textarea class="rex-input klxm-hub-keys" id="kh-keys" name="monitor[keys]" rows="3" spellcheck="false" placeholder="Agentur Hub ed25519:…">' . escape($keys) . '</textarea>', $t('monitor_keys_note'), 'kh-keys')
            . $field($t('monitor_backup'), $input('kh-bk', 'monitor[backup_dir]', $m['backup_dir'], 'text', ' spellcheck="false" placeholder="../backups"'), $t('monitor_backup_note'), 'kh-bk')
            . '<div class="rex-button-toolbar"><button type="submit" class="rex-button" data-variant="primary">' . escape($t('save')) . '</button></div></form>';
        $out .= new Section(title: $t('section_monitor'), body: Html::raw($body))->render();

        // ---------------------------------------------------------------- Vorschau der Antwort
        $preview = Status::build() + ['nonce' => '…'];
        $out .= new Section(title: $t('section_preview'), body: Html::raw('<p>' . escape($t('preview_note')) . '</p><pre class="klxm-hub-json">'
            . escape((string) json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>'), collapsible: true, collapsed: true)->render();

        return $out;
    }

    private static function action(): string
    {
        $do = Request::post('klxm_hub_do', 'string', '');
        if ('' === $do) {
            return '';
        }
        if (!CsrfToken::factory(self::CSRF)->isValid()) {
            return Message::error(HubAddon::t('csrf'));
        }
        $user = Core::requireUser()->login;
        if ('enroll' === $do) {
            if (Request::post('klxm_hub_cancel', 'string', '')) {
                Enroll::cancel();

                return Message::success(HubAddon::t('enroll_cancelled'));
            }
            self::$code = Enroll::create($user);
            Log::write('info', 'enroll-code', ['by' => $user]);

            return Message::success(HubAddon::t('enroll_created'));
        }
        if ('monitor' === $do) {
            $in = Request::post('monitor', 'array', []);
            $err = Settings::saveMonitor(!empty($in['enabled']), (string) ($in['keys'] ?? ''), (string) ($in['backup_dir'] ?? ''));
            if (null !== $err) {
                return Message::error(escape($err));
            }
            @unlink(\Redaxo\Core\Filesystem\Path::addonCache(HubAddon::NAME, 'status.json'));
            Log::write('info', 'settings-monitor', ['by' => $user, 'enabled' => (int) !empty($in['enabled'])]);

            return Message::success(HubAddon::t('saved_monitor'));
        }
        if ('oidc' !== $do) {
            return '';
        }
        if (Request::post('klxm_hub_forget', 'string', '')) {
            Settings::forgetSecret();
            Log::write('info', 'settings-oidc', ['by' => $user, 'secret' => 'removed']);

            return Message::success(HubAddon::t('secret_forgotten'));
        }
        $in = Request::post('oidc', 'array', []);
        $issuer = rtrim(trim((string) ($in['issuer'] ?? '')), '/');
        if ('' !== $issuer && !preg_match('~^https?://[^\s/?#]+(/[^\s?#]*)?$~', $issuer)) {
            return Message::error(HubAddon::t('err_issuer_format'));
        }
        Settings::saveOidc($in);
        Client::forgetCache($issuer);
        Log::write('info', 'settings-oidc', ['by' => $user, 'enabled' => (int) !empty($in['enabled'])]);
        $s = Settings::oidc();
        if (!$s['enabled']) {
            return Message::success(HubAddon::t('saved_oidc'));
        }
        if (!Settings::oidcReady($s)) {
            Settings::saveOidc(['enabled' => false] + $in);

            return Message::error(HubAddon::t('err_incomplete'));
        }
        try {
            Client::discovery($s['issuer'], true);
            \Klxm\Hub\Logo::refresh($s['issuer']);   // Logo des Anbieters (logo_uri) für den Anmelde-Link, optional
        } catch (OidcException $e) {
            Settings::saveOidc(['enabled' => false] + $in);
            Log::write('warning', 'settings-oidc', ['by' => $user, 'discovery' => $e->reason]);

            return Message::error(escape(HubAddon::t('err_discovery_save', $e->reason)));
        }
        $warn = 0 === Status::localAdmins() ? Message::warning(HubAddon::t('warn_no_local_admin')) : '';

        return Message::success(HubAddon::t('saved_oidc_ok')) . $warn;
    }
}
