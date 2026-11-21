<?php

namespace Klxm\Hub;

use Klxm\Hub\ExtensionPoint\HubUserMapping;
use Klxm\Hub\Oidc\Client;
use Klxm\Hub\Oidc\OidcException;
use Redaxo\Core\Database\Sql;
use Redaxo\Core\ExtensionPoint\Extension;
use Redaxo\Core\ExtensionPoint\ExtensionPoint;
use Redaxo\Core\Security\User;
use Redaxo\Core\Security\UserRole;

/**
 * Agentur-Konten: Zuordnung Anbieter-Konto (iss + sub) → REDAXO-Benutzer in rex_klxm_hub_identity – nie über die
 * E-Mail-Adresse. Regeln:
 *
 *  - Rolle aus dem Claim „roles“: admin → Admin, redaktion → die eingestellte Rolle; sonst keine Anmeldung.
 *    Bei jeder Anmeldung abgeglichen (Entzug im Hub wirkt bei der nächsten Anmeldung).
 *  - Vorhandene Benutzer (mit Passwort oder gleichem Login) werden nie übernommen.
 *  - Agentur-Konten haben kein Passwort (Anmeldung nur über den Hub) und sind in der Beschreibung gekennzeichnet.
 *    Bekommt ein Agentur-Konto lokal ein Passwort, lehnt der Hub-Zugang es ab – es ist dann ein lokales Konto.
 *  - Gesperrt (Status inaktiv in der Benutzerverwaltung) → keine Anmeldung.
 */
final class Accounts
{
    public const string TABLE = 'rex_klxm_hub_identity';
    public const string CREATE_USER = 'klxm_hub';

    /**
     * Rechte aus dem Claim „roles“.
     *
     * @param array<mixed> $claims
     * @return array{admin: bool, roles: list<int>}|null
     */
    public static function mapping(array $claims, int $redaktionRole): ?array
    {
        $roles = Client::roles($claims);
        $map = null;
        if (\in_array('admin', $roles, true)) {
            $map = ['admin' => true, 'roles' => []];
        } elseif (\in_array('redaktion', $roles, true) && $redaktionRole > 0 && self::roleExists($redaktionRole)) {
            $map = ['admin' => false, 'roles' => [$redaktionRole]];
        }

        return Extension::dispatch(new HubUserMapping($map, $claims, $roles));
    }

    private static function roleExists(int $id): bool
    {
        $sql = Sql::factory();
        $sql->setQuery('SELECT id FROM rex_user_role WHERE id = ?', [$id]);

        return $sql->getRows() > 0;
    }

    /**
     * Konto zur Anmeldung finden oder anlegen. @throws OidcException
     *
     * @param array<mixed> $claims geprüftes ID-Token
     * @param array{issuer: string, jit: bool, role_redaktion: int, name: string} $s
     * @return array{user_id: int, created: bool}
     */
    public static function resolve(array $claims, array $s): array
    {
        $iss = $s['issuer'];
        $sub = (string) $claims['sub'];
        $map = self::mapping($claims, $s['role_redaktion']);
        if (null === $map) {
            throw new OidcException('role', HubAddon::t('err_role'));
        }
        $email = \is_string($claims['email'] ?? null) && filter_var($claims['email'], FILTER_VALIDATE_EMAIL) ? mb_strtolower($claims['email']) : null;
        $name = \is_string($claims['name'] ?? null) && '' !== trim($claims['name']) ? mb_substr(trim($claims['name']), 0, 190) : ($email ?? 'Agentur');

        $sql = Sql::factory();
        $sql->setQuery('SELECT i.id, i.user_id, u.id AS uid, u.status, u.password FROM ' . self::TABLE . ' i LEFT JOIN rex_user u ON u.id = i.user_id WHERE i.subject_hash = ?', [self::subjectHash($iss, $sub)]);
        if ($sql->getRows() && null !== $sql->getValue('uid')) {
            $userId = (int) $sql->getValue('uid');
            if (1 !== (int) $sql->getValue('status')) {
                throw new OidcException('locked', HubAddon::t('err_locked'));
            }
            if ('' !== (string) $sql->getValue('password')) {
                throw new OidcException('local_password', HubAddon::t('err_local_password'));
            }
            self::apply($userId, $map, $name, $email, $s['name']);
            Sql::factory()->setTable(self::TABLE)->setWhere(['id' => (int) $sql->getValue('id')])
                ->setValue('email', $email)->setDateTimeValue('last_login', time())->update();

            return ['user_id' => $userId, 'created' => false];
        }
        if ($sql->getRows()) {
            // Benutzer wurde lokal gelöscht – alte Zuordnung verwerfen
            Sql::factory()->setQuery('DELETE FROM ' . self::TABLE . ' WHERE id = ?', [(int) $sql->getValue('id')]);
        }
        if (!$s['jit']) {
            throw new OidcException('no_account', HubAddon::t('err_no_account'));
        }
        $login = self::login($iss, $sub, $email);
        $check = Sql::factory();
        $check->setQuery('SELECT id FROM rex_user WHERE login = ?', [$login]);
        if ($check->getRows()) {
            // gleicher Login lokal vorhanden: nie übernehmen
            throw new OidcException('login_taken', HubAddon::t('err_login_taken'));
        }
        $ins = Sql::factory()->setTable('rex_user')
            ->setValue('login', $login)
            ->setValue('name', $name)
            ->setValue('email', $email)
            ->setValue('description', HubAddon::t('account_description', $s['name']))
            ->setValue('password', null)
            ->setValue('admin', $map['admin'] ? 1 : 0)
            ->setValue('status', 1)
            ->setValue('login_tries', 0)
            ->setValue('password_change_required', 0)
            ->addGlobalCreateFields(self::CREATE_USER)
            ->addGlobalUpdateFields(self::CREATE_USER)
            ->insert();
        $userId = $ins->getLastId();
        UserRole::setForUser($userId, $map['roles']);
        User::clearInstance($userId);
        Sql::factory()->setTable(self::TABLE)
            ->setValue('issuer', $iss)->setValue('sub', $sub)->setValue('subject_hash', self::subjectHash($iss, $sub))->setValue('user_id', $userId)->setValue('email', $email)
            ->setDateTimeValue('created', time())->setDateTimeValue('last_login', time())->insert();
        Extension::dispatch(new ExtensionPoint('USER_ADDED', '', ['id' => $userId, 'user' => User::require($userId), 'password' => ''], true));

        return ['user_id' => $userId, 'created' => true];
    }

    /** @param array{admin: bool, roles: list<int>} $map */
    private static function apply(int $userId, array $map, string $name, ?string $email, string $provider): void
    {
        Sql::factory()->setTable('rex_user')->setWhere(['id' => $userId])
            ->setValue('admin', $map['admin'] ? 1 : 0)
            ->setValue('name', $name)
            ->setValue('email', $email)
            ->setValue('description', HubAddon::t('account_description', $provider))
            ->addGlobalUpdateFields(self::CREATE_USER)
            ->update();
        UserRole::setForUser($userId, $map['roles']);
        User::clearInstance($userId);
    }

    /** Schlüssel der Zuordnung: SHA-256 über Issuer und sub (exakt, auch bei Groß-/Kleinschreibung) */
    public static function subjectHash(string $iss, string $sub): string
    {
        return hash('sha256', $iss . "\n" . $sub);
    }

    /** Login: E-Mail-Adresse (wenn kurz genug), sonst hub-<Fingerabdruck> */
    public static function login(string $iss, string $sub, ?string $email): string
    {
        if (null !== $email && mb_strlen($email) <= 50) {
            return $email;
        }

        return 'hub-' . substr(hash('sha256', $iss . '|' . $sub), 0, 12);
    }

    /** Ist der Benutzer ein Agentur-Konto? */
    public static function isAgency(int $userId): bool
    {
        $sql = Sql::factory();
        $sql->setQuery('SELECT id FROM ' . self::TABLE . ' WHERE user_id = ? LIMIT 1', [$userId]);

        return $sql->getRows() > 0;
    }

    /** @return list<array<string, mixed>> Agentur-Konten mit Benutzerdaten */
    public static function all(): array
    {
        /** @var list<array<string, mixed>> */
        return Sql::factory()->getArray('SELECT i.id AS identity_id, i.issuer, i.created, i.last_login, i.email AS hub_email, u.id, u.login, u.name, u.admin, u.status, u.password
            FROM ' . self::TABLE . ' i JOIN rex_user u ON u.id = i.user_id ORDER BY u.name, u.login');
    }

    public static function setStatus(int $userId, bool $active): void
    {
        if (!self::isAgency($userId)) {
            return;
        }
        Sql::factory()->setTable('rex_user')->setWhere(['id' => $userId])->setValue('status', $active ? 1 : 0)->addGlobalUpdateFields()->update();
        User::clearInstance($userId);
        if (!$active) {
            // laufende Sitzungen sofort beenden
            Sql::factory()->setQuery('DELETE FROM rex_user_session WHERE user_id = ?', [$userId]);
        }
    }

    /** USER_DELETED: Zuordnung entfernen */
    public static function forget(int $userId): void
    {
        Sql::factory()->setQuery('DELETE FROM ' . self::TABLE . ' WHERE user_id = ?', [$userId]);
    }
}
