/**
 * Saving the settings of a vectorization settings modal.
 *
 * @spec exclude UI plumbing shared by the vectorization settings modals.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

/**
 * Send the settings of a modal, report the outcome, and close the modal
 * when they are saved. The modal's saving flag is raised while it runs.
 *
 * @param {object} modal The modal component
 * @param {{send: function(): Promise, done: string}} save What sends the settings, and the message when they are saved
 * @return {Promise<void>}
 */
export async function saveSettings(modal, save) {
	modal.saving = true
	try {
		await save.send()
		showSuccess(save.done)
		modal.$emit('closing')
	} catch (error) {
		showError(saveFailure(error))
	} finally {
		modal.saving = false
	}
}

/**
 * Why the settings could not be saved, in words.
 *
 * @param {object} error The request error
 * @return {string} The translated message
 */
function saveFailure(error) {
	return t('openregister', 'Failed to save configuration: {error}', {
		error: error.response?.data?.error || error.message,
	})
}

/**
 * The hint under a field that sets how many file chunks go in one call.
 *
 * @return {string} The hint
 */
export function chunkBatchSizeHint() {
	return t(
		'openregister',
		'Number of chunks to vectorize in one API call. Higher = faster but more memory. Recommended: 10-50.',
	)
}
