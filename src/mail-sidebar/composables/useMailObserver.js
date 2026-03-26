/**
 * Composable that observes Mail app URL changes and extracts account/message IDs.
 *
 * Supports both hash-based routing (legacy Mail) and path-based routing (Mail 5.x+).
 *
 * @package OpenRegister
 */

import { ref, onMounted, onBeforeUnmount } from 'vue'

/**
 * Parse the Mail app URL to extract accountId and messageId.
 *
 * Handles both routing modes:
 * - Path-based (Mail 5.x+): /apps/mail/box/priority/thread/6 or /apps/mail/box/2/thread/42
 * - Hash-based (legacy): #/accounts/1/folders/INBOX/messages/42
 *
 * @param {string} url The full URL or hash string.
 * @return {{ accountId: number|null, messageId: number|null, sender: string|null }} Parsed IDs.
 */
export function parseMailUrl(url) {
	if (!url) {
		return { accountId: null, messageId: null, sender: null }
	}

	// Path-based routing: /apps/mail/box/{boxId}/thread/{threadId}
	const pathMatch = url.match(/\/apps\/mail\/box\/([^/]+)\/thread\/(\d+)/)
	if (pathMatch) {
		// boxId can be 'priority', 'starred', or a numeric mailbox ID
		const boxId = pathMatch[1]
		const threadId = parseInt(pathMatch[2], 10)

		// For priority/starred inboxes, accountId is unknown (uses default account 1)
		// For numeric box IDs, that IS the mailbox ID (not account ID)
		// We use 1 as default accountId since most setups have one account
		const accountId = /^\d+$/.test(boxId) ? 1 : 1

		return { accountId, messageId: threadId, sender: null }
	}

	// Hash-based routing: #/accounts/{accountId}/folders/{folderName}/messages/{messageId}
	const hashMatch = url.match(/\/accounts\/(\d+)\/folders\/[^/]+\/messages\/(\d+)/)
	if (hashMatch) {
		return {
			accountId: parseInt(hashMatch[1], 10),
			messageId: parseInt(hashMatch[2], 10),
			sender: null,
		}
	}

	return { accountId: null, messageId: null, sender: null }
}

/**
 * Composable for observing Mail app URL changes.
 *
 * Uses a combination of hashchange, popstate, and MutationObserver to detect
 * SPA navigation in the Mail app (which uses Vue Router with history mode).
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
	let lastUrl = ''
	let urlPollInterval = null

	function checkUrlChange() {
		const currentUrl = window.location.href

		if (currentUrl === lastUrl) {
			return
		}

		lastUrl = currentUrl

		if (debounceTimer) {
			clearTimeout(debounceTimer)
		}

		debounceTimer = setTimeout(() => {
			const parsed = parseMailUrl(currentUrl)

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
		// Parse initial URL.
		const currentUrl = window.location.href
		lastUrl = currentUrl
		const parsed = parseMailUrl(currentUrl)
		accountId.value = parsed.accountId
		messageId.value = parsed.messageId
		isMessageView.value = parsed.messageId !== null

		// Listen for hash changes (legacy routing).
		window.addEventListener('hashchange', checkUrlChange)

		// Listen for popstate (browser back/forward).
		window.addEventListener('popstate', checkUrlChange)

		// Poll for URL changes (catches Vue Router pushState which doesn't fire events).
		// This is the most reliable way to detect SPA navigation.
		urlPollInterval = setInterval(checkUrlChange, 500)
	})

	onBeforeUnmount(() => {
		window.removeEventListener('hashchange', checkUrlChange)
		window.removeEventListener('popstate', checkUrlChange)
		if (urlPollInterval) {
			clearInterval(urlPollInterval)
		}
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
