/**
 * How a relation tab that fetches its own data runs that fetch.
 *
 * @spec exclude Client-side tab plumbing; each relation's contract lives in its own capability.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * A mixin for a `relationTab` that loads by itself: `loading` while the
 * fetch runs, the failure state cleared before and set when it fails.
 */
export const relationLoad = {
	methods: {
		/**
		 * @param {function(): Promise<void>} fetch The fetch, storing what it reads
		 * @return {Promise<void>} Settles once the fetch has finished or failed
		 * @spec exclude loading and failure state of the tab, UI plumbing
		 */
		async runLoad(fetch) {
			this.loading = true
			this.clearFailure()
			try {
				await fetch()
			} catch (err) {
				this.showFailure(err)
			} finally {
				this.loading = false
			}
		},
	},
}
