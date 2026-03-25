/**
 * Composable that observes Mail app URL changes and extracts account/message IDs.
 *
 * @package OpenRegister
 */

import { ref, onMounted, onBeforeUnmount } from 'vue'

/**
 * Parse the Mail app URL to extract accountId and messageId.
 *
 * Supports both routing styles:
 * - Hash routing (Mail <5.x): #/accounts/1/folders/INBOX/messages/42
 * - Path routing (Mail 5.x+): /apps/mail/box/2/thread/42 or /apps/mail/box/priority/thread/42
 *
 * @param {string} url The full URL or hash string.
 * @return {{ accountId: number|null, messageId: number|null }} Parsed IDs.
 */
export function parseMailUrl(url) {
	if (!url) {
		return { accountId: null, messageId: null }
	}

	// Path routing (Mail 5.x): /apps/mail/box/{mailboxId}/thread/{threadId}
	// or /apps/mail/box/priority/thread/{threadId}
	const pathThreadMatch = url.match(/\/apps\/mail\/box\/(\w+)\/thread\/(\d+)/)
	if (pathThreadMatch) {
		return {
			accountId: pathThreadMatch[1] === 'priority' ? null : parseInt(pathThreadMatch[1], 10),
			messageId: parseInt(pathThreadMatch[2], 10),
		}
	}

	// Path routing: /apps/mail/box/{mailboxId} (no message selected)
	const pathBoxMatch = url.match(/\/apps\/mail\/box\/(\d+)$/)
	if (pathBoxMatch) {
		return { accountId: parseInt(pathBoxMatch[1], 10), messageId: null }
	}

	// Hash routing (legacy): /accounts/{accountId}/folders/{folderName}/messages/{messageId}
	const messageMatch = url.match(/\/accounts\/(\d+)\/folders\/[^/]+\/messages\/(\d+)/)
	if (messageMatch) {
		return {
			accountId: parseInt(messageMatch[1], 10),
			messageId: parseInt(messageMatch[2], 10),
		}
	}

	// Hash routing: folder-only pattern (no message selected)
	const folderMatch = url.match(/\/accounts\/(\d+)\/folders\//)
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

	function handleUrlChange() {
		if (debounceTimer) {
			clearTimeout(debounceTimer)
		}

		debounceTimer = setTimeout(() => {
			const url = window.location.hash || window.location.href
			const parsed = parseMailUrl(url)

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
		// Parse initial URL (check hash first, fall back to full URL for path routing)
		const url = window.location.hash || window.location.href
		const parsed = parseMailUrl(url)
		accountId.value = parsed.accountId
		messageId.value = parsed.messageId
		isMessageView.value = parsed.messageId !== null

		// Listen for both hash changes (legacy) and popstate (path routing)
		window.addEventListener('hashchange', handleUrlChange)
		window.addEventListener('popstate', handleUrlChange)

		// Mail app uses Vue Router push which doesn't fire popstate — also observe clicks
		const observer = new MutationObserver(() => {
			const currentUrl = window.location.hash || window.location.href
			const current = parseMailUrl(currentUrl)
			if (current.messageId !== messageId.value) {
				handleUrlChange()
			}
		})
		observer.observe(document.body, { childList: true, subtree: true })
		handleUrlChange._observer = observer
	})

	onBeforeUnmount(() => {
		window.removeEventListener('hashchange', handleUrlChange)
		window.removeEventListener('popstate', handleUrlChange)
		if (handleUrlChange._observer) {
			handleUrlChange._observer.disconnect()
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
