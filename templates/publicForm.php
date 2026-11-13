<?php
/**
 * The public page of a form open to visitors without an account; the script renders into the div.
 *
 * @license EUPL-1.2
 */

use OCA\OpenRegister\Service\ScriptManifestLoader;

$appId = OCA\OpenRegister\AppInfo\Application::APP_ID;
ScriptManifestLoader::addEntryScripts($appId, 'publicForm', $appId.'-public-form');
?>
<div id="openregister-public-form"></div>
