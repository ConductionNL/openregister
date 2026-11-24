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
import pinia from './pinia.js'
import { useNavigationStore } from './store/modules/navigation.js'

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
 * @spec openspec/specs/app-connections/spec.md
 */
export function openIntegriqConnections() {
	window.location.assign(generateUrl(INTEGRIQ_CONNECTIONS_PATH))
}

/**
 * The dialog the Connections page's "Daily report by e-mail" action opens.
 */
export const CONNECTION_REPORT_DIALOG = 'connectionReport'

/**
 * The Connections page's Daily report by e-mail header action.
 *
 * Opens ConnectionReportDialog, which Dialogs.vue renders while the navigation
 * store names it as the active dialog.
 *
 * @return {void}
 * @spec openspec/changes/connections-daily-report-mail/tasks.md#task-4.1
 */
export function openConnectionReportDialog() {
	useNavigationStore(pinia).setDialog(CONNECTION_REPORT_DIALOG)
}

export default {
	openIntegriqConnections,
	openConnectionReportDialog,
}
