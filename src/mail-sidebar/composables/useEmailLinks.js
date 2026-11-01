/**
 * Composable for managing email link API state. Powers the three-tab
 * sidebar's Objects + Link data flow (cache, abort, sender-suggestion
 * de-dup against linked uuids).
 *
 * @package
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 */

import { ref } from 'vue'
import {
	createQuickLink,
	deleteEmailLink,
	fetchLinkedObjects,
	fetchSenderObjects,
} from '../api/emailLinks.js'

/**
 * Drop sender suggestions that are already linked.
 *
 * @param {object} senderResult The sender lookup answer.
 * @param {Array<object>} linked The linked objects.
 * @return {Array<object>} The suggestions not yet linked.
 */
function withoutLinked(senderResult, linked) {
	const linkedUuids = new Set(linked.map((obj) => obj.objectUuid))
	return (senderResult.results || []).filter(
		(obj) => !linkedUuids.has(obj.objectUuid),
	)
}

/**
 * Whether an error means the request was cancelled.
 *
 * @param {Error} err The error.
 * @return {boolean} True for an aborted or cancelled request.
 */
function isAbort(err) {
	return err.name === 'AbortError' || err.name === 'CanceledError'
}

/**
 * Classify a failed load for the error state.
 *
 * @param {Error} err The error.
 * @return {string} 'server', 'timeout' or 'network'.
 */
function errorKind(err) {
	if (err.response?.status >= 500) {
		return 'server'
	}
	return err.code === 'ECONNABORTED' ? 'timeout' : 'network'
}

/**
 * Composable for email link data management with caching.
 *
 * @return {object} Reactive state and methods.
 */
export function useEmailLinks() {
	const linkedObjects = ref([])
	const suggestedObjects = ref([])
	const loading = ref(false)
	const error = ref(null)
	const total = ref(0)
	const suggestedTotal = ref(0)

	// Cache: messageKey -> { linked, suggested, timestamp }
	const cache = {}
	let currentAbortController = null

	/**
	 * Generate a cache key from accountId and messageId.
	 *
	 * @param {number} accountId The account ID.
	 * @param {number} messageId The message ID.
	 * @return {string} The cache key.
	 */
	function cacheKey(accountId, messageId) {
		return `${accountId}:${messageId}`
	}

	/**
	 * Show a cached entry.
	 *
	 * @param {object} entry The cache entry with linked and suggested lists.
	 */
	function showCached(entry) {
		linkedObjects.value = entry.linked
		suggestedObjects.value = entry.suggested
		total.value = entry.linked.length
		suggestedTotal.value = entry.suggested.length
		error.value = null
	}

	/**
	 * Abort any in-flight load and start a new one.
	 *
	 * @return {AbortSignal} The signal of the new load.
	 */
	function startLoad() {
		if (currentAbortController) {
			currentAbortController.abort()
		}
		currentAbortController = new AbortController()
		loading.value = true
		error.value = null
		return currentAbortController.signal
	}

	/**
	 * Fetch linked objects and sender suggestions, show them and cache them.
	 *
	 * @param {object} request The accountId, messageId, sender and cache key.
	 * @param {AbortSignal} signal The abort signal of this load.
	 */
	async function fetchAndCache(request, signal) {
		const linkedResult = await fetchLinkedObjects(
			request.accountId,
			request.messageId,
			signal,
		)
		linkedObjects.value = linkedResult.results || []
		total.value = linkedResult.total || 0

		suggestedObjects.value = request.sender
			? withoutLinked(
					await fetchSenderObjects(request.sender, signal),
					linkedObjects.value,
				)
			: []
		suggestedTotal.value = suggestedObjects.value.length

		cache[request.key] = {
			linked: [...linkedObjects.value],
			suggested: [...suggestedObjects.value],
			timestamp: Date.now(),
		}
	}

	/**
	 * Load linked objects and sender suggestions for an email.
	 *
	 * @param {number} accountId The mail account ID.
	 * @param {number} messageId The mail message ID.
	 * @param {string} [sender] The sender email address for discovery.
	 * @param {boolean} [useCache] Whether to use cached results.
	 */
	async function loadForMessage(accountId, messageId, sender, useCache = true) {
		const key = cacheKey(accountId, messageId)

		if (useCache && cache[key]) {
			showCached(cache[key])
			refreshInBackground(accountId, messageId, sender, key)
			return
		}

		const signal = startLoad()
		try {
			await fetchAndCache({ accountId, messageId, sender, key }, signal)
		} catch (err) {
			if (isAbort(err)) {
				return
			}
			error.value = errorKind(err)
			linkedObjects.value = []
			suggestedObjects.value = []
		} finally {
			loading.value = false
		}
	}

	/**
	 * Refresh data in the background without showing loading state.
	 *
	 * @param {number} accountId The mail account ID.
	 * @param {number} messageId The mail message ID.
	 * @param {string} sender The sender email.
	 * @param {string} key The cache key.
	 */
	async function refreshInBackground(accountId, messageId, sender, key) {
		try {
			const linkedResult = await fetchLinkedObjects(accountId, messageId)
			const newLinked = linkedResult.results || []

			let newSuggested = []
			if (sender) {
				newSuggested = withoutLinked(
					await fetchSenderObjects(sender),
					newLinked,
				)
			}

			// Update only if this is still the active message
			if (cache[key]) {
				cache[key] = {
					linked: newLinked,
					suggested: newSuggested,
					timestamp: Date.now(),
				}
				linkedObjects.value = newLinked
				suggestedObjects.value = newSuggested
				total.value = newLinked.length
				suggestedTotal.value = newSuggested.length
			}
		} catch {
			// Silent failure for background refresh
		}
	}

	/**
	 * Link an object to the current email.
	 *
	 * @param {object} params The quick-link parameters.
	 * @return {object} The created link.
	 */
	async function linkObject(params) {
		const result = await createQuickLink(params)
		// Invalidate cache
		const key = cacheKey(params.mailAccountId, params.mailMessageId)
		delete cache[key]
		return result
	}

	/**
	 * Unlink an object from the current email.
	 *
	 * @param {number} linkId The link ID to delete.
	 * @param {number} accountId The mail account ID.
	 * @param {number} messageId The mail message ID.
	 * @return {object} The response.
	 */
	async function unlinkObject(linkId, accountId, messageId) {
		const result = await deleteEmailLink(linkId)
		// Invalidate cache
		const key = cacheKey(accountId, messageId)
		delete cache[key]
		return result
	}

	/**
	 * Clear all state.
	 */
	function clear() {
		linkedObjects.value = []
		suggestedObjects.value = []
		loading.value = false
		error.value = null
		total.value = 0
		suggestedTotal.value = 0
	}

	return {
		linkedObjects,
		suggestedObjects,
		loading,
		error,
		total,
		suggestedTotal,
		loadForMessage,
		linkObject,
		unlinkObject,
		clear,
	}
}
