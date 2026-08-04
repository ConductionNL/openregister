<?php

use OCA\OpenRegister\Service\ScriptManifestLoader;
use OCP\Util;

$appId = OCA\OpenRegister\AppInfo\Application::APP_ID;
ScriptManifestLoader::addEntryScripts($appId, 'main', $appId.'-main');
Util::addStyle($appId, 'main');
?>
<div id="openregister"></div>


