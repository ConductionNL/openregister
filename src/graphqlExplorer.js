/**
 * Entry for the GraphQL explorer page (api-explorer-in-the-app, REQ-APIX-001).
 *
 * GraphiQL, React and the editor workers are bundled with the app, so the page
 * loads no script from outside the instance. Loaded by templates/graphqlExplorer.php
 * from GraphQLController::explorer().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/api-explorer-in-the-app/tasks.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { GraphiQL } from 'graphiql'
import { createElement } from 'react'
import { createRoot } from 'react-dom/client'
import { createExplorerFetcher } from './services/graphqlExplorerFetcher.js'

import 'graphiql/setup-workers/webpack'
import 'graphiql/style.css'

const fetcher = createExplorerFetcher(axios, generateUrl('/apps/openregister/api/graphql'))

createRoot(document.getElementById('openregister-graphql-explorer')).render(
	createElement(GraphiQL, { fetcher, defaultEditorToolsVisibility: true }),
)
