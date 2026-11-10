/**
 * Function handlers the manifest names, passed to CnAppRoot as `customComponents`.
 *
 * CnIndexPage resolves a header action's `handler` string against this map.
 * Pages stay in `registry.js`; this file only holds handlers that no manifest
 * keyword can express.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

import { generateUrl } from '@nextcloud/router'

/**
 * Where Add integration lands: integriq's overview, preset to OpenRegister, linking.
 */
export const INTEGRIQ_CONNECTIONS_PATH =
	'/apps/integriq/connections?app=openregister&link=1'

/**
 * The Connections page's Add integration header action.
 *
 * A connection row is integriq's, and a source is linked to it on integriq's
 * Connections overview (hydra connection-registry D9). `link=1` opens the
 * link-a-source dialog there, filtered to OpenRegister's connections.
 *
 * A function because a header action's `navigate` keyword only pushes a route
 * name inside this app's router, which cannot leave the app.
 *
 * @return {void}
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
 */
export function openIntegriqConnections() {
	window.location.assign(generateUrl(INTEGRIQ_CONNECTIONS_PATH))
}

export default {
	openIntegriqConnections,
}
