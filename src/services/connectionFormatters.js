/**
 * Formatters for the Connections page (hydra connection-registry, design D8).
 *
 * The pinned @conduction/nextcloud-vue 2.48.1 does not ship `connectionStatus`
 * and `connectionSettingsLabel` as built-ins (they arrived in a later release),
 * so OpenRegister carries a local copy. CnAppRoot merges these over the
 * built-ins, so the local copy keeps rendering after a library bump until it is
 * removed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * The six statuses integriq resolves, with the label a reader sees.
 * Kept as source strings so the l10n extractor finds each one.
 */
export const CONNECTION_STATUS_LABELS = Object.freeze({
	configured: () => t('openregister', 'Configured'),
	limited: () => t('openregister', 'Limited'),
	unconfigured: () => t('openregister', 'Not configured'),
	simulated: () => t('openregister', 'Simulated'),
	unavailable: () => t('openregister', 'Not available'),
	error: () => t('openregister', 'Error'),
})

/**
 * The label for a connection status, translated on each call.
 *
 * An unknown value renders itself rather than an empty cell: a status the page
 * cannot name is still a status the admin should see.
 *
 * @param {string} value The `status` enum value.
 * @return {string} The label, the raw value when unknown, or '' when missing.
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
 */
export function connectionStatus(value) {
	if (value === undefined || value === null) {
		return ''
	}
	const label = Object.hasOwn(CONNECTION_STATUS_LABELS, value)
		? CONNECTION_STATUS_LABELS[value]
		: null
	return label ? label() : String(value)
}

/**
 * The Open settings link text, or '' when the row has nowhere to send a reader.
 *
 * @param {string} value The row's `settingsUrl`.
 * @return {string} The link text, or ''.
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
 */
export function connectionSettingsLabel(value) {
	return typeof value === 'string' && value.length > 0
		? t('openregister', 'Open settings')
		: ''
}

export default {
	connectionStatus,
	connectionSettingsLabel,
}
