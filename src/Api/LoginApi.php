<?php

namespace Klxm\Hub\Api;

use Klxm\Hub\Accounts;
use Klxm\Hub\HubAddon;
use Klxm\Hub\Log;
use Klxm\Hub\Oidc\Client;
use Klxm\Hub\Oidc\OidcException;
use Klxm\Hub\RateLimit;
use Klxm\Hub\Settings;
use Redaxo\Core\ApiFunction\ApiFunction;
use Redaxo\Core\ApiFunction\AsApiFunction;
use Redaxo\Core\ApiFunction\Result;
use Redaxo\Core\Core;
use Redaxo\Core\Database\Sql;
use Redaxo\Core\Filesystem\Url;
use Redaxo\Core\Http\Request;
use Redaxo\Core\Http\Response;
use Redaxo\Core\Security\BackendLogin;
use Redaxo\Core\Security\Login;
use Redaxo\Core\Security\UserSession;

/**
 * Anmeldung im Backend über den Hub (OpenID Connect).
 *
 *   …/redaxo/index.php?rex-api-call=klxm_hub&step=start      Knopf auf der Anmeldeseite bzw. Einstieg vom Hub
 *                                                             (optional &iss=<Issuer>&login_hint=<E-Mail>)
 *   …/redaxo/index.php?rex-api-call=klxm_hub&step=callback   Rückkehr vom Hub (Redirect-URI)
 *
 * Öffentlich (vor der Anmeldung erreichbar) und ohne CSRF-Token des Cores – den Schutz übernimmt der state, der an die
 * Backend-Sitzung gebunden und nur einmal verwendbar ist. Fehler erscheinen als Meldung auf der Anmeldeseite (HTTP 400),
 * ohne Einzelheiten; der Grund steht im Systemprotokoll. Ausgeschaltet → 404.
 */
#[AsApiFunction('klxm_hub')]
final class LoginApi extends ApiFunction
{
    protected bool $published = true;
    protected bool $requiresCsrfProtection = false;

    /** Rate-Limit je IP: Anfragen bzw. Fehlversuche in 10 Minuten */
    private const int MAX_REQUESTS = 30;
    private const int MAX_FAILURES = 10;
    private const int WINDOW = 600;

    public function execute(): Result
    {
        $s = Settings::oidc();
        $step = Request::get('step', 'string', '');
        if (!Core::isBackend() || !Settings::oidcReady($s) || !\in_array($step, ['start', 'callback'], true)) {
            self::notFound();
        }
        if (RateLimit::tooMany(RateLimit::ip('login'), self::MAX_REQUESTS, self::WINDOW) || RateLimit::tooMany(RateLimit::ip('login-fail'), self::MAX_FAILURES, self::WINDOW)) {
            Log::write('warning', 'login-denied', ['reason' => 'rate_limit']);

            return self::show(HubAddon::t('err_rate_limit'), '429 Too Many Requests');
        }
        RateLimit::hit(RateLimit::ip('login'));

        try {
            if ('start' === $step) {
                self::start($s);
            }
            self::callback($s);
        } catch (OidcException $e) {
            RateLimit::hit(RateLimit::ip('login-fail'));
            Log::write('warning', 'login-denied', ['reason' => $e->reason]);

            return self::show($e->getMessage() ?: HubAddon::t('err_generic'));
        }
    }

    /** @param array{name: string, issuer: string, client_id: string} $s */
    private static function start(array $s): never
    {
        // Bereits angemeldet: direkt ins Backend
        if (Core::getUser()) {
            Response::sendRedirect(Url::backendController());
        }
        $iss = Request::get('iss', 'string', '');
        if ('' !== $iss && !hash_equals($s['issuer'], rtrim($iss, '/'))) {
            throw new OidcException('start:iss', HubAddon::t('err_generic'));
        }
        $hint = Request::get('login_hint', 'string', '');
        $hint = filter_var($hint, FILTER_VALIDATE_EMAIL) ? $hint : '';
        Response::sendRedirect(Client::begin($s, $hint));
    }

    /** @param array{enabled: bool, name: string, issuer: string, client_id: string, client_secret: string, auth: string, jit: bool, role_redaktion: int} $s */
    private static function callback(array $s): never
    {
        $error = Request::get('error', 'string', '');
        $state = Request::get('state', 'string', '');
        $pend = '' !== $state ? Client::takeState($state) : null;
        if ('' !== $error) {
            throw new OidcException('provider:' . preg_replace('~[^\w.-]~', '', mb_substr($error, 0, 40)), HubAddon::t('err_provider_cancelled'));
        }
        if (null === $pend || !hash_equals((string) ($pend['iss'] ?? ''), $s['issuer'])) {
            throw new OidcException('state', HubAddon::t('err_state'));
        }
        // RFC 9207: iss in der Antwort muss der eingestellte Issuer sein (Pflicht, wenn der Anbieter ihn ankündigt)
        $iss = Request::get('iss', 'string', '');
        $disc = Client::discovery($s['issuer']);
        if ('' !== $iss ? !hash_equals($s['issuer'], rtrim($iss, '/')) : !empty($disc['authorization_response_iss_parameter_supported'])) {
            throw new OidcException('iss', HubAddon::t('err_generic'));
        }
        $res = Client::finish($s, $pend, Request::get('code', 'string', ''));
        $claims = $res['claims'];
        if (!Client::strongAmr($claims)) {
            throw new OidcException('mfa', HubAddon::t('err_mfa'));
        }
        $acc = Accounts::resolve($claims, $s);
        self::login($acc['user_id']);
        Log::write('info', 'login-ok', ['user' => $acc['user_id'], 'created' => (int) $acc['created']]);
        Response::sendRedirect(Url::backendController());
    }

    /**
     * Benutzer in der Backend-Sitzung anmelden – wie die Anmeldung per Passkey im Core (BackendLogin::checkLogin):
     * Sitzung erneuern, Benutzer-ID ohne Passwort-Hash, Start/Aktivität setzen, Sitzung in rex_user_session eintragen.
     *
     * Der Core bietet dafür (noch) keine öffentliche Methode; UserSession ist als @internal markiert. Sobald es eine
     * gibt (Vorschlag: BackendLogin::loginUser(User $user)), wird sie hier verwendet.
     */
    private static function login(int $userId): void
    {
        $login = BackendLogin::getCurrent() ?? new BackendLogin();
        Login::regenerateSessionId();
        $login->setSessionVar(Login::SESSION_USER_ID, $userId);
        $login->setSessionVar(Login::SESSION_PASSWORD, null);
        $login->setSessionVar(Login::SESSION_IMPERSONATOR, null);
        $login->setSessionVar(Login::SESSION_START_TIME, time());
        $login->setSessionVar(Login::SESSION_LAST_ACTIVITY, time());
        $login->setSessionVar(BackendLogin::SESSION_STAY_LOGGED_IN, false);
        UserSession::getInstance()->storeCurrentSession($login);
        UserSession::clearExpiredSessions();
        Sql::factory()->setQuery('UPDATE rex_user SET login_tries = 0, lasttrydate = ?, lastlogin = ? WHERE id = ? LIMIT 1', [Sql::datetime(), Sql::datetime(), $userId]);
    }

    /** Aus bzw. unbekannter Schritt: schlicht 404 (ohne Ausnahme – sie landete im Modus dev im Systemprotokoll) */
    private static function notFound(): never
    {
        Response::cleanOutputBuffers();
        Response::setStatus(Response::HTTP_NOT_FOUND);
        Response::sendCacheControl('no-store');
        Response::sendContent('Not found', 'text/plain; charset=utf-8');
        exit;
    }

    /** Meldung auf der Anmeldeseite des Backends */
    private static function show(string $message, string $status = Response::HTTP_BAD_REQUEST): Result
    {
        Response::setStatus($status);
        if ($login = BackendLogin::getCurrent()) {
            $login->message = $message;
        }

        return new Result(false);
    }
}
