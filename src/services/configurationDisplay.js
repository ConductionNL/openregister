/**
 * Labels and the version check shared by the configuration card, the
 * configuration list and the import modal.
 *
 * @spec exclude Client-side display helpers for configurations.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { configurationStore } from '../store/store.js'

const SOURCE_LABELS = {
	local: 'Local',
	github: 'GitHub',
	gitlab: 'GitLab',
	url: 'URL',
}

const UNSYNCED_TEXTS = {
	failed: 'Sync failed',
	pending: 'Sync pending',
}

/**
 * A human label for a source type id.
 *
 * @param {string} sourceType The source type id
 * @return {string} The label
 */
export function sourceTypeLabel(sourceType) {
	return SOURCE_LABELS[sourceType] || 'Unknown'
}

/**
 * How long ago a sync was, in words.
 *
 * @param {string} lastSyncDate The date of the last sync
 * @return {string} The text
 */
function syncAgeText(lastSyncDate) {
	const hours = Math.floor((new Date() - new Date(lastSyncDate)) / 3600000)
	if (hours < 1) return 'Synced just now'
	if (hours < 24) return `Synced ${hours}h ago`
	return `Synced ${Math.floor(hours / 24)}d ago`
}

/**
 * The sync state of a configuration, in words.
 *
 * @param {object} configuration The configuration
 * @return {string} The text
 */
export function syncStatusText(configuration) {
	if (configuration.syncStatus === 'success' && configuration.lastSyncDate) {
		return syncAgeText(configuration.lastSyncDate)
	}
	return UNSYNCED_TEXTS[configuration.syncStatus] || 'Never synced'
}

/**
 * Tell the user what a version check found.
 *
 * @param {object} data The check-version response
 * @return {void}
 */
function reportVersion(data) {
	if (!data.hasUpdate) {
		showSuccess(t('openregister', 'Configuration is up to date'))
		return
	}
	showSuccess(
		t('openregister', 'Update available: {local} → {remote}', {
			local: data.localVersion,
			remote: data.remoteVersion,
		}),
	)
}

/**
 * Check a configuration against its remote version and refresh the list.
 *
 * @param {object} configuration The configuration
 * @return {Promise<void>}
 */
export async function checkConfigurationVersion(configuration) {
	try {
		const response = await requestVersionCheck(configuration.id)
		reportVersion(response.data)
		await configurationStore.refreshConfigurationList()
	} catch (error) {
		showError(versionCheckFailedText(error))
	}
}

/**
 * Ask the server to check a configuration's remote version.
 *
 * @param {number|string} id The configuration id
 * @return {Promise<object>} The axios response
 */
function requestVersionCheck(id) {
	return axios.post(
		generateUrl(`/apps/openregister/api/configurations/${id}/check-version`),
	)
}

/**
 * What a failed version check says: the server's error, else the failure's.
 *
 * @param {Error} error What went wrong
 * @return {string} The message
 */
function versionCheckFailedText(error) {
	return t('openregister', 'Failed to check version: {error}', {
		error: error.response?.data?.error || error.message,
	})
}
