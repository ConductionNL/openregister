<?php
/**
 * The holder's page for an access link; the script renders into the div.
 *
 * @license EUPL-1.2
 */

use OCA\OpenRegister\Service\ScriptManifestLoader;

$appId = OCA\OpenRegister\AppInfo\Application::APP_ID;
ScriptManifestLoader::addEntryScripts($appId, 'accessLink', $appId.'-access-link');
?>
<div id="openregister-access-link"></div>
