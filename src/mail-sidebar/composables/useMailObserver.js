/**
 * Composable that observes Mail app URL changes and extracts account/message IDs.
 *
 * Supports both hash-based routing (legacy Mail) and path-based routing (Mail 5.x+).
 *
 * @package
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 */

import { ref, onMounted, onBeforeUnmount } from 'vue'

/**
 * Parse the Mail app URL to extract accountId and messageId.
 *
 * Handles both routing modes:
 * - Path-based (Mail 5.x+): /apps/mail/box/priority/thread/6 or /apps/mail/box/2/thread/42
 * - Path-based (no message): /apps/mail/box/2
 * - Hash-based (legacy): #/accounts/1/folders/INBOX/messages/42
 * - Hash-based folder-only (no message): #/accounts/1/folders/INBOX
 *
 * @param {string} url The full URL or hash string.
 * @return {{ accountId: number|null, messageId: number|null, sender: string|null }} Parsed IDs.
 */
export function parseMailUrl(url) {
	if (!url) {
		return { accountId: null, messageId: null, sender: null }
	}

	// Path-based routing with thread: /apps/mail/box/{boxId}/thread/{threadId}
	// boxId may be 'priority', 'starred', or a numeric mailbox ID.
	const pathThreadMatch = url.match(/\/apps\/mail\/box\/(\w+)\/thread\/(\d+)/)
	if (pathThreadMatch) {
		const boxId = pathThreadMatch[1]
		const threadId = parseInt(pathThreadMatch[2], 10)

		// For priority/starred inboxes, accountId is unknown — fall back to the
		// default account 1 (most setups have one account). For numeric box IDs
		// the segment is a mailbox ID, not an account ID; still fall back to 1.
		const accountId = /^\d+$/.test(boxId) ? 1 : 1

		return { accountId, messageId: threadId, sender: null }
	}

	// Path-based routing without thread: /apps/mail/box/{mailboxId}
	const pathBoxMatch = url.match(/\/apps\/mail\/box\/(\d+)$/)
	if (pathBoxMatch) {
		return {
			accountId: parseInt(pathBoxMatch[1], 10),
			messageId: null,
			sender: null,
		}
	}

	// Hash-based routing: #/accounts/{accountId}/folders/{folderName}/messages/{messageId}
	const hashMessageMatch = url.match(
		/\/accounts\/(\d+)\/folders\/[^/]+\/messages\/(\d+)/,
	)
	if (hashMessageMatch) {
		return {
			accountId: parseInt(hashMessageMatch[1], 10),
			messageId: parseInt(hashMessageMatch[2], 10),
			sender: null,
		}
	}

	// Hash-based folder-only: #/accounts/{accountId}/folders/...
	const hashFolderMatch = url.match(/\/accounts\/(\d+)\/folders\//)
	if (hashFolderMatch) {
		return {
			accountId: parseInt(hashFolderMatch[1], 10),
			messageId: null,
			sender: null,
		}
	}

	return { accountId: null, messageId: null, sender: null }
}

/**
 * Composable for observing Mail app URL changes.
 *
 * Uses a combination of hashchange, popstate, and polling to detect SPA
 * navigation in the Mail app (which uses Vue Router with history mode and
 * pushState — neither hashchange nor popstate fires for pushState).
 *
 * @param {object} options Options.
 * @param {number} [options.debounceMs] Debounce delay in milliseconds.
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
			// Prefer hash for legacy Mail, fall back to full URL for path routing.
			const url = window.location.hash || currentUrl
			const parsed = parseMailUrl(url)

			const changed =
				parsed.accountId !== accountId.value
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
		const url = window.location.hash || currentUrl
		const parsed = parseMailUrl(url)
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
