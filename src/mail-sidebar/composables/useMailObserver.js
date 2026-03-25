/**
 * Composable that observes Mail app URL changes and extracts account/message IDs.
 *
 * @package OpenRegister
 */

import { ref, onMounted, onBeforeUnmount } from 'vue'

/**
 * Parse the Mail app URL hash to extract accountId and messageId.
 *
 * Handles patterns like:
 * - #/accounts/1/folders/INBOX/messages/42
 * - #/accounts/2/folders/Archief/messages/108
 * - #/accounts/1/folders/INBOX (no message selected)
 * - #/compose, #/settings (non-message views)
 *
 * @param {string} hash The URL hash string.
 * @return {{ accountId: number|null, messageId: number|null }} Parsed IDs.
 */
export function parseMailUrl(hash) {
	if (!hash || hash === '#' || hash === '#/') {
		return { accountId: null, messageId: null }
	}

	// Match pattern: /accounts/{accountId}/folders/{folderName}/messages/{messageId}
	const messageMatch = hash.match(/\/accounts\/(\d+)\/folders\/[^/]+\/messages\/(\d+)/)
	if (messageMatch) {
		return {
			accountId: parseInt(messageMatch[1], 10),
			messageId: parseInt(messageMatch[2], 10),
		}
	}

	// Match folder-only pattern (no message selected)
	const folderMatch = hash.match(/\/accounts\/(\d+)\/folders\//)
	if (folderMatch) {
		return { accountId: parseInt(folderMatch[1], 10), messageId: null }
	}

	return { accountId: null, messageId: null }
}

/**
 * Composable for observing Mail app URL changes.
 *
 * @param {object} options Options.
 * @param {number} [options.debounceMs=300] Debounce delay in milliseconds.
 * @param {Function} [options.onChange] Callback when accountId/messageId change.
 * @return {object} Reactive state with accountId, messageId, and isMessageView.
 */
export function useMailObserver(options = {}) {
	const debounceMs = options.debounceMs || 300
	const onChange = options.onChange || null

	const accountId = ref(null)
	const messageId = ref(null)
	const isMessageView = ref(false)

	let debounceTimer = null

	function handleHashChange() {
		if (debounceTimer) {
			clearTimeout(debounceTimer)
		}

		debounceTimer = setTimeout(() => {
			const parsed = parseMailUrl(window.location.hash)

			const changed = parsed.accountId !== accountId.value
				|| parsed.messageId !== messageId.value

			accountId.value = parsed.accountId
			messageId.value = parsed.messageId
			isMessageView.value = parsed.messageId !== null

			if (changed && onChange) {
				onChange(parsed)
			}
		}, debounceMs)
	}

	onMounted(() => {
		// Parse initial URL
		const parsed = parseMailUrl(window.location.hash)
		accountId.value = parsed.accountId
		messageId.value = parsed.messageId
		isMessageView.value = parsed.messageId !== null

		// Listen for hash changes
		window.addEventListener('hashchange', handleHashChange)
	})

	onBeforeUnmount(() => {
		window.removeEventListener('hashchange', handleHashChange)
		if (debounceTimer) {
			clearTimeout(debounceTimer)
		}
	})

	return {
		accountId,
		messageId,
		isMessageView,
	}
}
