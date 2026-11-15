/**
 * The transient save message the file configuration and API token sections
 * show next to their controls.
 *
 * Spread into a view's `methods`; `showSaveMessage` writes the view's
 * `saveMessage` and `saveMessageType`.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
export const saveMessageMethods = {
	/**
	 * Show a save message and clear its text after three seconds.
	 *
	 * @spec exclude UI plumbing — transient inline message with auto-clear timeout.
	 * @param {string} message - The message to show
	 * @param {string} type - The type of message ('success' or 'error')
	 * @return {void}
	 */
	showSaveMessage(message, type = 'success') {
		this.saveMessage = message
		this.saveMessageType = type
		setTimeout(() => {
			this.saveMessage = ''
		}, 3000)
	},
}
