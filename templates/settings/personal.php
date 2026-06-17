<?php
use OCA\OpenRegister\Service\ScriptManifestLoader;
use OCP\Util;

$appId = OCA\OpenRegister\AppInfo\Application::APP_ID;
ScriptManifestLoader::addEntryScripts($appId, 'personalSettings', $appId.'-personalSettings');
Util::addStyle($appId, 'main');

?>

<div id="openregister-personal-settings"></div>
