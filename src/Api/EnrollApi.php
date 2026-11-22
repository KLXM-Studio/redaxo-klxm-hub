<?php

namespace Klxm\Hub\Api;

use Klxm\Hub\Enroll;
use Redaxo\Core\ApiFunction\ApiFunction;
use Redaxo\Core\ApiFunction\AsApiFunction;
use Redaxo\Core\ApiFunction\Result;
use Redaxo\Core\Core;

/**
 * Verbindung per Einmal-Code: POST /index.php?rex-api-call=klxm_hub_enroll (JSON) – nur mit offenem Code, {@see Enroll}.
 */
#[AsApiFunction('klxm_hub_enroll')]
final class EnrollApi extends ApiFunction
{
    protected bool $published = true;
    protected bool $requiresCsrfProtection = false;

    public function execute(): Result
    {
        if ('POST' !== Core::getRequest()->getMethod()) {
            StatusApi::send(['error' => 'method_not_allowed'], '405 Method Not Allowed');
        }
        $in = json_decode((string) Core::getRequest()->getContent(), true);
        [$status, $body] = Enroll::handle(\is_array($in) ? $in : []);
        StatusApi::send($body, match ($status) { 200 => '200 OK', 400 => '400 Bad Request', 401 => '401 Unauthorized', 429 => '429 Too Many Requests', default => '500 Internal Server Error' });
    }
}
