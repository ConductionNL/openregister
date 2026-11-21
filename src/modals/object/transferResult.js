/**
 * What the merge and migration dialogs share about a transfer's outcome.
 *
 * @spec exclude Client-side result shape of the object transfer dialogs.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * The result a dialog shows when its transfer failed: the error's message,
 * or the dialog's own fallback when the error has none.
 *
 * @param {Error} error What went wrong
 * @param {string} fallback The message to show without one
 * @return {object} The failed result
 */
export function failedTransfer(error, fallback) {
	return {
		success: false,
		errors: [error.message || fallback],
	}
}
