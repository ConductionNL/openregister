/**
 * The status line of an account section: one message, shown as success or
 * error, rendered by components/shared/SectionStatus.vue.
 *
 * @spec exclude UI plumbing — client-side status message state of a section.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
export const sectionStatus = {
	data() {
		return {
			message: '',
			isError: false,
		}
	},

	methods: {
		/**
		 * Show a status message.
		 *
		 * @spec exclude UI plumbing — sets the section status message
		 * @param {string} text The message
		 * @param {boolean} [isError] Whether it reports a failure
		 * @return {void}
		 */
		setStatus(text, isError = false) {
			this.message = text
			this.isError = isError
		},

		/**
		 * Show a failed request: the API's own error, or the fallback text.
		 *
		 * @spec exclude UI plumbing — sets the section status message from a failed request
		 * @param {object} e The axios error
		 * @param {string} fallback The message when the API gave none
		 * @return {void}
		 */
		setStatusError(e, fallback) {
			this.setStatus(e.response?.data?.error || fallback, true)
		},
	},
}
