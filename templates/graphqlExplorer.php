<?php
/**
 * The GraphQL explorer page (api-explorer-in-the-app, REQ-APIX-001).
 *
 * GraphiQL is bundled with the app, so the page loads no script from outside
 * the instance. Served by GraphQLController::explorer().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/api-explorer-in-the-app/tasks.md#task-1.2
 */

use OCA\OpenRegister\Service\ScriptManifestLoader;

$appId = OCA\OpenRegister\AppInfo\Application::APP_ID;
ScriptManifestLoader::addEntryScripts($appId, 'graphqlExplorer', $appId.'-graphqlExplorer');
?>
<div id="openregister-graphql-explorer" style="height:100%;width:100%"></div>
