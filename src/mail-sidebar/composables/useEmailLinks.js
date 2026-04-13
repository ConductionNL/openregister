/**
 * Composable for managing email link API state.
 *
 * @package OpenRegister
 */

import { ref } from 'vue'
import {
	fetchLinkedObjects,
	fetchSenderObjects,
	createQuickLink,
	deleteEmailLink,
} from '../api/emailLinks.js'

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
	 * Load linked objects and sender suggestions for an email.
	 *
	 * @param {number} accountId The mail account ID.
	 * @param {number} messageId The mail message ID.
	 * @param {string} [sender] The sender email address for discovery.
	 * @param {boolean} [useCache=true] Whether to use cached results.
	 */
	async function loadForMessage(accountId, messageId, sender, useCache = true) {
		const key = cacheKey(accountId, messageId)

		// Check cache
		if (useCache && cache[key]) {
			linkedObjects.value = cache[key].linked
			suggestedObjects.value = cache[key].suggested
			total.value = cache[key].linked.length
			suggestedTotal.value = cache[key].suggested.length
			error.value = null

			// Background refresh
			refreshInBackground(accountId, messageId, sender, key)
			return
		}

		// Cancel any in-flight request
		if (currentAbortController) {
			currentAbortController.abort()
		}
		currentAbortController = new AbortController()

		loading.value = true
		error.value = null

		try {
			const signal = currentAbortController.signal

			// Fetch linked objects
			const linkedResult = await fetchLinkedObjects(accountId, messageId, signal)
			linkedObjects.value = linkedResult.results || []
			total.value = linkedResult.total || 0

			// Fetch sender suggestions if sender is provided
			if (sender) {
				const senderResult = await fetchSenderObjects(sender, signal)
				const linkedUuids = new Set(
					linkedObjects.value.map((obj) => obj.objectUuid),
				)
				suggestedObjects.value = (senderResult.results || []).filter(
					(obj) => !linkedUuids.has(obj.objectUuid),
				)
				suggestedTotal.value = suggestedObjects.value.length
			} else {
				suggestedObjects.value = []
				suggestedTotal.value = 0
			}

			// Update cache
			cache[key] = {
				linked: [...linkedObjects.value],
				suggested: [...suggestedObjects.value],
				timestamp: Date.now(),
			}
		} catch (err) {
			if (err.name === 'AbortError' || err.name === 'CanceledError') {
				return
			}
			error.value = err.response?.status >= 500
				? 'server'
				: (err.code === 'ECONNABORTED' ? 'timeout' : 'network')
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
				const senderResult = await fetchSenderObjects(sender)
				const linkedUuids = new Set(newLinked.map((obj) => obj.objectUuid))
				newSuggested = (senderResult.results || []).filter(
					(obj) => !linkedUuids.has(obj.objectUuid),
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
