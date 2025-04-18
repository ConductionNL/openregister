<?php

use OCP\Util;

$appId = OCA\OpenRegister\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId.'-main');
Util::addStyle($appId, 'main');
?>
<div id="openregister"></div>


