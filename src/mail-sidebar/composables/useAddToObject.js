/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An "Add to case" action on every attachment in Mail's reading pane
 * (MlConnections): it adds the attachment to the item the mail is connected
 * to, as dropping it on the card does. The action shows only while the mail
 * is connected; its label names the kind of item.
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 */
import { onBeforeUnmount, onMounted, watch } from 'vue'
import { attachmentPayloadOf } from './useAttachmentDrag.js'

const ATTACHMENT_SELECTOR = '#mail-message .attachment'
const ACTION_CLASS = 'or-mail-add-to-object'
const PLUS_PATH = 'M19,13H13V19H11V13H5V11H11V5H13V11H19V13Z'

/**
 * Give one attachment its action, or update the one it has.
 *
 * @param {Element} el The `.attachment` element.
 * @param {string} label The action's label; empty hides it.
 * @param {function(object): void} onAdd Called with the attachment's payload.
 */
export function decorateAttachment(el, label, onAdd) {
	let button = el.querySelector(`:scope > .${ACTION_CLASS}`)
	if (!button) {
		button = document.createElement('button')
		button.type = 'button'
		button.className = ACTION_CLASS
		button.innerHTML = `<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="${PLUS_PATH}"/></svg><span></span>`
		button.addEventListener('click', (event) => {
			event.preventDefault()
			event.stopPropagation()
			const payload = attachmentPayloadOf(el)
			if (payload) onAdd(payload)
		})
		const menu = el.querySelector(':scope > .action-item')
		el.insertBefore(button, menu)
	}
	button.querySelector('span').textContent = label
	button.hidden = label === ''
}

/**
 * Keep the action on every attachment Mail renders.
 *
 * @param {{value: string}} label A ref with the label; '' hides the action.
 * @param {function(object): void} onAdd Called with the attachment's payload.
 */
export function useAddToObject(label, onAdd) {
	let observer = null
	const scan = () => {
		for (const el of document.querySelectorAll(ATTACHMENT_SELECTOR)) {
			decorateAttachment(el, label.value, onAdd)
		}
	}

	onMounted(() => {
		scan()
		observer = new MutationObserver((mutations) => {
			if (mutations.some((m) => [...m.addedNodes].some((node) => node.nodeType === 1 && !node.classList?.contains(ACTION_CLASS)))) {
				scan()
			}
		})
		observer.observe(document.body, { childList: true, subtree: true })
	})

	watch(label, scan)

	onBeforeUnmount(() => {
		observer?.disconnect()
		observer = null
	})
}

