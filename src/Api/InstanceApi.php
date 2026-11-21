<?php

namespace Klxm\Hub\Api;

use Klxm\Hub\InstanceInfo;
use Redaxo\Core\ApiFunction\ApiFunction;
use Redaxo\Core\ApiFunction\AsApiFunction;
use Redaxo\Core\ApiFunction\Result;

/**
 * Selbstauskunft klxm-instance-v1 ohne Umschreibregel: GET /index.php?rex-api-call=klxm_hub_instance
 * (gleicher Inhalt wie /.well-known/klxm-studio, {@see InstanceInfo}).
 */
#[AsApiFunction('klxm_hub_instance')]
final class InstanceApi extends ApiFunction
{
    protected bool $published = true;
    protected bool $requiresCsrfProtection = false;

    public function execute(): Result
    {
        InstanceInfo::send();
    }
}
