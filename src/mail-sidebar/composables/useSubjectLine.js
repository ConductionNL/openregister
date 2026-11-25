/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The line under the subject in Mail's reading pane, as MlConnections draws
 * it: "1 message · Inbox · connected to case #2026-4833". The message count
 * and mailbox come from what Mail shows; the connection from the panel.
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 */
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { onBeforeUnmount, onMounted, watch } from 'vue'
import { objectIdentifier } from '../objectRow.js'
import { sentenceName } from '../sentenceName.js'

const LINE_CLASS = 'or-mail-subject-line'

/**
 * The text of the line.
 *
 * @param {number} messages How many messages the conversation shows.
 * @param {string} mailbox The open mailbox's name, or ''.
 * @param {Array<object>} connected The items the mail is connected to.
 * @return {string}
 */
export function subjectLineText(messages, mailbox, connected) {
	const parts = []
	if (messages > 0) parts.push(n('openregister', '%n message', '%n messages', messages))
	if (mailbox) parts.push(mailbox)
	const first = (connected || [])[0]
	if (first) {
		const name = sentenceName(first.schema || '')
		const number = objectIdentifier(first)
		parts.push(number
			? t('openregister', 'connected to {name} #{number}', { name, number })
			: t('openregister', 'connected to {name}', { name }))
	}
	return parts.join(' · ')
}

/**
 * Put the line under the subject, or update it.
 *
 * @param {Array<object>} connected The items the mail is connected to.
 */
export function renderSubjectLine(connected) {
	const fields = document.getElementById('mail-thread-header-fields')
	if (!fields) return
	const messages = document.querySelectorAll('#mail-message .envelope').length
	const mailbox = document.querySelector('#app-navigation-vue .app-navigation-entry.active .app-navigation-entry__name')?.textContent.trim() || ''
	const text = subjectLineText(messages, mailbox, connected)
	let line = fields.querySelector(`:scope > .${LINE_CLASS}`)
	if (!line) {
		line = document.createElement('p')
		line.className = LINE_CLASS
		fields.appendChild(line)
	}
	if (line.textContent !== text) line.textContent = text
	line.hidden = text === ''
}

/**
 * Keep the line under the subject while Mail renders the conversation.
 *
 * @param {{value: Array<object>}} connected A ref with the connected items.
 */
export function useSubjectLine(connected) {
	let observer = null
	const render = () => renderSubjectLine(connected.value)

	onMounted(() => {
		render()
		observer = new MutationObserver((mutations) => {
			if (mutations.some((m) => !(m.target instanceof Element) || !m.target.closest?.(`.${LINE_CLASS}`))) {
				render()
			}
		})
		observer.observe(document.body, { childList: true, subtree: true })
	})

	watch(connected, render)

	onBeforeUnmount(() => {
		observer?.disconnect()
		observer = null
	})
}
