/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Talk action "Save chat to object": read the conversation the action was
 * run in, through Talk's own chat API as the signed-in user, and save it as a
 * text file on the register object the caller picks.
 *
 * Registered from the Talk script entry (talk-actions.js), which
 * TalkAppScriptListener adds to Talk's page. Talk offers other apps message
 * actions, so the action sits in a message's menu and saves the conversation
 * that message belongs to.
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-talk-action-saves-a-conversation-to-a-register-object
 */

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'

/** Messages asked of Talk per page (Talk's own maximum). */
export const PAGE_SIZE = 200

/** The most messages one save reads; the server refuses more (TalkChatExporter::MAX_MESSAGES). */
export const MAX_MESSAGES = 5000

/**
 * A Talk message's text with its placeholders (`{file}`, `{mention-user1}`)
 * replaced by the names Talk gives them.
 *
 * @param {object} message A Talk chat message
 * @return {string}
 */
export function messageText(message) {
	const parameters = message?.messageParameters ?? {}
	return String(message?.message ?? '').replace(/{([\w-]+)}/g, (whole, key) => {
		const name = parameters?.[key]?.name
		return typeof name === 'string' && name !== '' ? name : whole
	})
}

/**
 * The conversation's messages, oldest first, as the transcript route takes them.
 *
 * Reads backwards from the newest message, a page at a time, until Talk has no
 * older ones or MAX_MESSAGES are read. System messages (joins, renames, calls)
 * are left out: the transcript is what people wrote.
 *
 * @param {string} token The conversation token
 * @return {Promise<Array<{timestamp: number, actorDisplayName: string, message: string}>>}
 */
export async function fetchConversationMessages(token) {
	const url = generateOcsUrl('apps/spreed/api/v1/chat/{token}', { token })
	const messages = []
	let lastKnownMessageId = null
	let read = 0
	while (read < MAX_MESSAGES) {
		const params = { lookIntoFuture: 0, limit: PAGE_SIZE, setReadMarker: 0 }
		if (lastKnownMessageId !== null) {
			params.lastKnownMessageId = lastKnownMessageId
		}
		let page
		try {
			const { data } = await axios.get(url, { params })
			page = data?.ocs?.data ?? []
		} catch (e) {
			// Talk answers 304 when there is nothing older: the read is complete.
			if (e?.response?.status === 304) {
				break
			}
			throw e
		}
		if (!Array.isArray(page) || page.length === 0) {
			break
		}
		read += page.length
		for (const message of page) {
			if (typeof message?.systemMessage === 'string' && message.systemMessage !== '') {
				continue
			}
			messages.push({
				timestamp: Number(message?.timestamp ?? 0),
				actorDisplayName: String(message?.actorDisplayName ?? ''),
				message: messageText(message),
			})
		}
		const ids = page.map((message) => Number(message?.id)).filter(Number.isFinite)
		const oldest = Math.min(...ids)
		if (page.length < PAGE_SIZE || !Number.isFinite(oldest) || oldest === lastKnownMessageId) {
			break
		}
		lastKnownMessageId = oldest
	}
	return messages
		.slice(0, MAX_MESSAGES)
		.sort((a, b) => a.timestamp - b.timestamp)
}

/**
 * Save the conversation a Talk message belongs to.
 *
 * Talk calls this with the message the menu was opened on and the
 * conversation it belongs to (`metadata`).
 *
 * @param {{metadata?: {token?: string, displayName?: string, name?: string}}} context What Talk hands the action
 * @return {Promise<boolean|null>} true when saved, false on a failure, null when closed without a pick
 */
export async function saveChat({ metadata } = {}) {
	const token = metadata?.token
	if (typeof token !== 'string' || token === '') {
		return null
	}
	// The picker, its dialog and the toasts load on first use, so Talk's
	// page stays light for everyone who never runs the action.
	const [{ spawnDialog }, { default: AttachToObjectDialog }, { showError, showSuccess }] = await Promise.all([
		import(/* webpackChunkName: "talk-actions-app" */ '@nextcloud/vue/functions/dialog'),
		import(/* webpackChunkName: "talk-actions-app" */ '../dialogs/files/AttachToObjectDialog.vue'),
		import(/* webpackChunkName: "talk-actions-app" */ '@nextcloud/dialogs'),
	])
	let messages
	try {
		messages = await fetchConversationMessages(token)
	} catch {
		showError(t('openregister', 'The conversation could not be read.'))
		return false
	}
	if (messages.length === 0) {
		showError(t('openregister', 'The conversation has no messages to save.'))
		return false
	}
	const conversation = String(metadata?.displayName || metadata?.name || '')
	const saved = await spawnDialog(AttachToObjectDialog, { chat: { conversation, messages } })
	if (!saved) {
		// Closed without picking: not a failure, nothing to report.
		return null
	}
	if (!Array.isArray(saved.attached) || saved.attached.length === 0) {
		showError(t('openregister', 'The file could not be added to the object.'))
		return false
	}
	showSuccess(t('openregister', 'Added to {title}', { title: saved.object.title }))
	return true
}

/**
 * The Talk message action, labelled in the caller's language.
 *
 * Built when it is registered rather than at load, so the label is translated
 * with the catalogue Nextcloud adds beside the script.
 *
 * @return {{label: string, icon: string, callback: (context: object) => Promise<boolean|null>}}
 */
export function createSaveChatAction() {
	return {
		label: t('openregister', 'Save chat to object'),
		icon: 'icon-folder',
		callback: saveChat,
	}
}

/**
 * Register the action with Talk, when Talk offers message actions.
 *
 * @param {object|undefined} talk Talk's frontend API (`window.OCA.Talk`)
 * @return {boolean} Whether the action was registered
 */
export function registerSaveChatAction(talk = window.OCA?.Talk) {
	if (typeof talk?.registerMessageAction !== 'function') {
		return false
	}
	talk.registerMessageAction(createSaveChatAction())
	return true
}
