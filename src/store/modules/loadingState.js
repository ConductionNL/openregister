/**
 * The loading and error state the quality and reports stores keep around
 * their requests.
 *
 * @spec exclude Client-side loading and error state around API passthroughs.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * The text of a failed request: the server's error, else the error's message,
 * else the fallback.
 *
 * @param {object} error The failure.
 * @param {string} fallback The text when the failure names none.
 * @return {string} The text.
 * @spec exclude Client-side wording of a failed API passthrough.
 */
export function failureText(error, fallback) {
	return error.response?.data?.error ?? error.message ?? fallback
}

/**
 * Run a request with the store's loading flag raised and its error cleared; a
 * failure is stored, logged and rethrown.
 *
 * @param {object} store The store.
 * @param {{run: function(): Promise<object>, failure: string, log: function(Error): void}} job
 *     The request, the fallback error text and what logs a failure.
 * @return {Promise<object>} What the request returns.
 * @spec exclude Client-side loading and error state around API passthroughs.
 */
export async function whileLoading(store, job) {
	store.loading = true
	store.error = null
	try {
		return await job.run()
	} catch (error) {
		store.error = failureText(error, job.failure)
		job.log(error)
		throw error
	} finally {
		store.loading = false
	}
}
