/**
 * The busy and error state around a dialog's store request.
 *
 * @spec exclude Client-side dialog state plumbing shared by the delete dialogs.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * Run a dialog's request: `loading` while it runs, `error` cleared before it
 * and set to the error's message (or `failure` without one) when it throws.
 * `loading` ends either way.
 *
 * @param {object} dialog The dialog component (`loading`, `error`)
 * @param {() => Promise<void>} request The request, including what follows its success
 * @param {string} failure The message shown when the error carries none
 * @param {(error: Error) => void} [onError] Called with the error before `error` is set
 * @return {Promise<void>} Settles once the outcome is shown
 * @spec exclude Client-side dialog state plumbing around a store request.
 */
export async function runDialogRequest(dialog, request, failure, onError) {
	dialog.loading = true
	dialog.error = null
	try {
		await request()
	} catch (error) {
		if (onError) onError(error)
		dialog.error = error.message || failure
	} finally {
		dialog.loading = false
	}
}
