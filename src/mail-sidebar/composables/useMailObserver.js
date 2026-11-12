/**
 * Composable that observes Mail app URL changes and extracts account/message IDs.
 *
 * Supports both hash-based routing (legacy Mail) and path-based routing (Mail 5.x+).
 *
 * @package
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 */

import { onBeforeUnmount, onMounted, ref } from 'vue'

// The Mail routes the sidebar recognises, most specific first, and the ids each yields.
const MAIL_URL_ROUTES = [
	// Path-based routing with thread: /apps/mail/box/{boxId}/thread/{threadId}.
	// boxId may be 'priority', 'starred' or a numeric mailbox id; the account is
	// unknown either way, so it falls back to the default account 1.
	[
		/\/apps\/mail\/box\/(\w+)\/thread\/(\d+)/,
		(match) => ({ accountId: 1, messageId: parseInt(match[2], 10) }),
	],
	// Path-based routing without thread: /apps/mail/box/{mailboxId}.
	[
		/\/apps\/mail\/box\/(\d+)$/,
		(match) => ({ accountId: parseInt(match[1], 10), messageId: null }),
	],
	// Hash-based routing: #/accounts/{accountId}/folders/{folderName}/messages/{messageId}.
	[
		/\/accounts\/(\d+)\/folders\/[^/]+\/messages\/(\d+)/,
		(match) => ({
			accountId: parseInt(match[1], 10),
			messageId: parseInt(match[2], 10),
		}),
	],
	// Hash-based folder-only: #/accounts/{accountId}/folders/...
	[
		/\/accounts\/(\d+)\/folders\//,
		(match) => ({ accountId: parseInt(match[1], 10), messageId: null }),
	],
]

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
	for (const [pattern, ids] of MAIL_URL_ROUTES) {
		const match = url ? url.match(pattern) : null
		if (match) {
			return { ...ids(match), sender: null }
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

	/**
	 *
	 */
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
