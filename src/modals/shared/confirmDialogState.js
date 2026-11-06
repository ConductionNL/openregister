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
