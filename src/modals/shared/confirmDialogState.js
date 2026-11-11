/**
 * The state of a confirm dialog and how it closes.
 *
 * @spec exclude Client-side dialog state shared by the confirm dialogs.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { navigationStore } from '../../store/store.js'

/**
 * A mixin holding the outcome of the action and closing the dialog.
 *
 * @param {boolean|null} unanswered The value of `success` before any outcome
 * @return {object} The mixin
 */
export function confirmDialogState(unanswered) {
	return {
		data() {
			return {
				success: unanswered,
				loading: false,
				error: false,
				closeModalTimeout: null,
			}
		},

		methods: {
			/**
			 * @spec exclude Dialog close plumbing, resets the dialog state.
			 */
			closeDialog() {
				navigationStore.setDialog(false)
				clearTimeout(this.closeModalTimeout)
				this.success = unanswered
				this.loading = false
				this.error = false
			},
		},
	}
}

/**
 * Show the outcome of a confirm dialog's request: success, or the error
 * message (or `failure` without one), and close two seconds after success.
 * `loading` ends either way.
 *
 * @param {object} dialog The dialog component (`success`, `error`, `loading`, `closeModalTimeout`, `closeDialog`)
 * @param {Promise<{response: Response}>} request The store request
 * @param {string} failure The message shown when the error carries none
 * @return {Promise<void>} Settles once the outcome is shown
 */
export function settleConfirmRequest(dialog, request, failure) {
	return request
		.then(({ response }) => {
			dialog.success = response.ok
			dialog.error = false
			if (response.ok) {
				dialog.closeModalTimeout = setTimeout(dialog.closeDialog, 2000)
			}
		})
		.catch((error) => {
			dialog.success = false
			dialog.error = error.message || failure
		})
		.finally(() => {
			dialog.loading = false
		})
}
