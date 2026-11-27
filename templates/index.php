<?php

use OCA\OpenRegister\Service\ScriptManifestLoader;
use OCP\Util;

$appId = OCA\OpenRegister\AppInfo\Application::APP_ID;
ScriptManifestLoader::addEntryScripts($appId, 'main', $appId.'-main');
// nextcloud-vue's stylesheet, copied to css/ by the build
// (scripts/library-stylesheet-copy.js): linked, the browser keeps it parsed
// between page loads instead of the main bundle inserting it as text.
Util::addStyle($appId, 'nextcloud-vue');
Util::addStyle($appId, 'main');
?>
<div id="openregister"></div>


