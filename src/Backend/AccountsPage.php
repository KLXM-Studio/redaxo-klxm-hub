<?php

namespace Klxm\Hub\Backend;

use Klxm\Hub\Accounts;
use Klxm\Hub\HubAddon;
use Klxm\Hub\Log;
use Klxm\Hub\Monitoring\Status;
use Redaxo\Core\Core;
use Redaxo\Core\Filesystem\Url;
use Redaxo\Core\Http\Request;
use Redaxo\Core\Security\CsrfToken;
use Redaxo\Core\Util\Formatter;
use Redaxo\Core\View\Component\Section;
use Redaxo\Core\View\Html;
use Redaxo\Core\View\Message;

use function Redaxo\Core\View\escape;

/**
 * Backend → AddOns → Agentur Hub → Agentur-Konten: Liste der über den Hub angelegten Benutzer, lokal sperren/entsperren.
 * Löschen und alle weiteren Angaben über die Benutzerverwaltung des Cores.
 *
 * @internal
 */
final class AccountsPage
{
    private const string CSRF = 'klxm_hub_accounts';

    public static function run(): string
    {
        $out = self::action();
        $t = HubAddon::t(...);
        $csrf = CsrfToken::factory(self::CSRF)->getHiddenField();
        $rows = '';
        foreach (Accounts::all() as $a) {
            $active = 1 === (int) $a['status'];
            $hasPw = '' !== (string) $a['password'];
            $state = $active ? $t('state_active') : $t('state_locked');
            $rows .= '<tr>'
                . '<td><a href="' . escape(Url::backendPage('user/users', ['user_id' => (int) $a['id']])) . '">' . escape((string) ($a['name'] ?: $a['login'])) . '</a>'
                . '<br><small class="klxm-hub-muted">' . escape((string) $a['login']) . '</small></td>'
                . '<td>' . ((int) $a['admin'] ? escape($t('role_admin')) : escape($t('role_redaktion_short'))) . '</td>'
                . '<td><span class="klxm-hub-state" data-state="' . ($active ? 'active' : 'locked') . '">' . escape($state) . '</span>'
                . ($hasPw ? '<br><small class="klxm-hub-muted">' . escape($t('state_local_password')) . '</small>' : '') . '</td>'
                . '<td>' . escape(Formatter::intlDateTime(null === $a['last_login'] ? null : (string) $a['last_login'])) . '</td>'
                . '<td><form method="post">' . $csrf . '<input type="hidden" name="klxm_hub_do" value="' . ($active ? 'lock' : 'unlock') . '">'
                . '<input type="hidden" name="user_id" value="' . (int) $a['id'] . '">'
                . '<button type="submit" class="rex-button" data-size="xs"' . ($active ? ' data-variant="danger" data-confirm="' . escape($t('lock_confirm')) . '"' : '') . '>'
                . escape($active ? $t('lock') : $t('unlock')) . '</button></form></td>'
                . '</tr>';
        }
        $table = '<table class="rex-table" data-hover><thead><tr><th>' . escape($t('col_account')) . '</th><th>' . escape($t('col_role')) . '</th><th>'
            . escape($t('col_state')) . '</th><th>' . escape($t('col_last_login')) . '</th><th><span class="rex-u-visually-hidden">' . escape($t('col_actions')) . '</span></th></tr></thead><tbody>' . $rows . '</tbody></table>';
        $note = '<p>' . escape($t('accounts_note')) . '</p>';
        if (0 === Status::localAdmins()) {
            $note .= Message::warning($t('warn_no_local_admin'));
        }
        $out .= new Section(title: $t('page_accounts'), body: Html::raw($note . ('' === $rows ? '<p>' . escape($t('accounts_empty')) . '</p>' : '')),
            content: '' !== $rows ? Html::raw($table) : '')->render();

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
        $id = Request::post('user_id', 'int', 0);
        if ($id <= 0 || !Accounts::isAgency($id) || $id === Core::requireUser()->id) {
            return Message::error(HubAddon::t('err_account'));
        }
        $lock = 'lock' === $do;
        Accounts::setStatus($id, !$lock);
        Log::write('info', $lock ? 'account-locked' : 'account-unlocked', ['user' => $id, 'by' => Core::requireUser()->login]);

        return Message::success(HubAddon::t($lock ? 'locked' : 'unlocked'));
    }
}
