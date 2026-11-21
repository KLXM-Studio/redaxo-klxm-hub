<?php

use Redaxo\Core\Backend\Controller;
use Redaxo\Core\View\View;

echo View::title('KLXM Hub', Klxm\Hub\HubAddon::t('subtitle'));
Controller::includeCurrentPageSubPath();
