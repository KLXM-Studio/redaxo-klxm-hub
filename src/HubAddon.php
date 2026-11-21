<?php

namespace Klxm\Hub;

use Klxm\Hub\Oidc\Client;
use Override;
use Redaxo\Core\Addon\Addon;
use Redaxo\Core\Addon\OwnedSchema;
use Redaxo\Core\Backend\Controller;
use Redaxo\Core\Backend\MainPage;
use Redaxo\Core\Backend\Page;
use Redaxo\Core\Core;
use Redaxo\Core\Database\Column;
use Redaxo\Core\Database\Index;
use Redaxo\Core\Database\Table;
use Redaxo\Core\ExtensionPoint\Extension;
use Redaxo\Core\ExtensionPoint\ExtensionPoint;
use Redaxo\Core\Http\Request;
use Redaxo\Core\Translation\I18n;
use Redaxo\Core\Util\Type;
use Redaxo\Core\View\Asset;

use function Redaxo\Core\View\escape;

/**
 * Agentur Hub für REDAXO 6: Anmeldung im Backend über den Hub (OpenID Connect, {@see Api\LoginApi}) und signierte
 * Status-Abfrage für die Überwachung ({@see Api\StatusApi}). Beides ist aus, bis es unter AddOns → Agentur Hub
 * eingeschaltet wird.
 */
final class HubAddon extends Addon
{
    public const string NAME = 'klxm_hub';

    /** Übersetzung mit Präfix klxm_hub_ */
    public static function t(string $key, string|int ...$replacements): string
    {
        return I18n::msg(self::NAME . '_' . $key, ...$replacements);
    }

    #[Override]
    public function install(): void
    {
        // Zuordnung Anbieter-Konto (iss + sub) → REDAXO-Benutzer
        Table::get(Accounts::TABLE)
            ->ensurePrimaryIdColumn()
            ->ensureColumn(new Column('issuer', 'varchar(191)'))
            ->ensureColumn(new Column('sub', 'varchar(191)'))
            // SHA-256 von issuer + "\n" + sub: eindeutig und unabhängig von der Sortierung der Datenbank (sub ist case-sensitiv)
            ->ensureColumn(new Column('subject_hash', 'char(64)'))
            ->ensureColumn(new Column('user_id', 'int(10) unsigned'))
            ->ensureColumn(new Column('email', 'varchar(191)', true))
            ->ensureColumn(new Column('created', 'datetime', true))
            ->ensureColumn(new Column('last_login', 'datetime', true))
            ->ensureIndex(new Index('subject_hash', ['subject_hash'], Index::UNIQUE))
            ->ensureIndex(new Index('user_id', ['user_id']))
            ->ensure();
        // Rate-Limit und gesehene Nonces (Schlüssel als SHA-256)
        Table::get(RateLimit::TABLE)
            ->ensurePrimaryIdColumn()
            ->ensureColumn(new Column('k', 'char(64)'))
            ->ensureColumn(new Column('t', 'int(10) unsigned'))
            ->ensureIndex(new Index('k_t', ['k', 't']))
            ->ensure();
    }

    /** Tabellen entfernt `console migrate:cleanup`, sobald das AddOn per Composer entfernt ist (Agentur-Benutzer bleiben). */
    #[Override]
    public function getOwnedSchema(): OwnedSchema
    {
        return new OwnedSchema(tables: [Accounts::TABLE, RateLimit::TABLE]);
    }

    /** Backend: AddOns → Agentur Hub (Einstellungen, Agentur-Konten) – nur Admins */
    #[Override]
    public function getPages(): iterable
    {
        $main = new MainPage('addons', self::NAME, 'Agentur Hub');
        $main->setIcon('rex-icon fa-solid fa-right-to-bracket');
        $main->setRequiredPermissions('admin');
        $main->addSubpage(new Page('settings', self::t('page_settings')));
        $main->addSubpage(new Page('accounts', self::t('page_accounts')));

        yield $main;
    }

    #[Override]
    public function boot(): void
    {
        // Zuordnung entfernen, wenn der Benutzer im Core gelöscht wird
        Extension::register('USER_DELETED', static function (ExtensionPoint $ep): void {
            Accounts::forget(Type::int($ep->getParam('id')));
        });

        if (!Core::isBackend()) {
            return;
        }
        $page = Request::get('page', 'string', '');
        if (str_starts_with($page, self::NAME)) {
            Asset::addCssFile($this->getAssetsUrl('klxm_hub.css'));
        }

        // Anmeldeseite: Knopf „Mit Agentur Hub anmelden“ (nur wenn eingeschaltet und vollständig eingerichtet)
        if (!Core::getUser() && Settings::oidcReady()) {
            Asset::addCssFile($this->getAssetsUrl('klxm_hub.css'));
            Extension::register('OUTPUT_FILTER', static function (ExtensionPoint $ep): string {
                $html = (string) $ep->subject;
                if ('login' !== Controller::getCurrentPage()) {
                    return $html;
                }

                return self::injectButton($html);
            });
        }
    }

    /**
     * Knopf in die Anmeldeseite einfügen – im Fuß des Abschnitts im Formular #rex-form-login. Der Core hat dort (noch)
     * keinen Erweiterungspunkt; fehlt das Formular, bleibt die Seite unverändert.
     */
    public static function injectButton(string $html): string
    {
        $form = strpos($html, 'id="rex-form-login"');
        if (false === $form) {
            return $html;
        }
        // im Fuß des Abschnitts unter dem Login-Knopf (gleiche Abstände), sonst am Ende des Abschnitts
        $section = strpos($html, '</section>', $form);
        $footer = strpos($html, '</footer>', $form);
        $end = false !== $footer && (false === $section || $footer < $section) ? $footer : $section;
        if (false === $end) {
            return $html;
        }
        $name = Settings::oidc()['name'];
        $button = '<div class="klxm-hub-login">'
            . '<p class="klxm-hub-login-or"><span>' . escape(self::t('login_or')) . '</span></p>'
            . '<a class="rex-button klxm-hub-login-button" data-width="full" href="' . escape(Client::startUri()) . '">'
            . '<i class="rex-icon fa-solid fa-right-to-bracket" aria-hidden="true"></i> ' . escape(self::t('login_button', $name)) . '</a>'
            . '<p class="klxm-hub-login-note">' . escape(self::t('login_note')) . '</p>'
            . '</div>';

        return substr($html, 0, $end) . $button . substr($html, $end);
    }
}
