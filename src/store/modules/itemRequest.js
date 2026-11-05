/**
 * The loading and error state the application and webhook stores keep
 * around their requests.
 *
 * @spec exclude Client-side loading and error state around API passthroughs.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * Run a store request with its loading flag raised, unless the request is
 * soft. A failure is logged, stored as the store's error and rethrown,
 * wrapped in the `failure` text when one is given.
 *
 * @param {object} store The store.
 * @param {{run: function(): Promise<object>, log: function(Error): void, soft?: boolean, failure?: string}} job
 *     The request, what logs a failure, whether to leave the loading flag
 *     alone, and the text a failure is wrapped in.
 * @return {Promise<object>} What the request returns.
 * @spec exclude Client-side loading and error state around API passthroughs.
 */
export async function itemRequest(store, job) {
	if (!job.soft) store.loading = true
	try {
		return await job.run()
	} catch (error) {
		job.log(error)
		store.error = error.message
		throw job.failure
			? new Error(`${job.failure}: ${error.message}`, { cause: error })
			: error
	} finally {
		if (!job.soft) store.loading = false
	}
}
