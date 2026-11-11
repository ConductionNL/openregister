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
 * wrapped in the `failure` text when one is given. With `clearsError` the
 * store's error is cleared once the request has started.
 *
 * @param {object} store The store.
 * @param {{run: function(): Promise<object>, log: function(Error): void, soft?: boolean, failure?: string, clearsError?: boolean}} job
 *     The request, what logs a failure, whether to leave the loading flag
 *     alone, the text a failure is wrapped in, and whether starting the
 *     request clears the store's error.
 * @return {Promise<object>} What the request returns.
 * @spec exclude Client-side loading and error state around API passthroughs.
 */
export async function itemRequest(store, job) {
	if (!job.soft) store.loading = true
	try {
		const pending = job.run()
		if (job.clearsError) store.error = null
		return await pending
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
