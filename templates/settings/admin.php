<?php
use OCA\OpenRegister\Service\ScriptManifestLoader;
use OCP\Util;

$appId = OCA\OpenRegister\AppInfo\Application::APP_ID;
ScriptManifestLoader::addEntryScripts($appId, 'adminSettings', $appId.'-settings');
Util::addStyle($appId, 'main');

?>

<div id="settings"></div>