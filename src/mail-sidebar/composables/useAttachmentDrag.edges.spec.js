/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Edges of the attachment drag patch: Vue 3 props, nothing to offer, a
 * refusing dataTransfer, and the nodes the observer must skip.
 *
 * @spec exclude Unit test of the client-side mail attachment drag patch.
 */
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { ATTACHMENT_MIME, useAttachmentDrag } from './useAttachmentDrag.js'

let observerCallback

/**
 * Drag data that records what is set, or refuses to.
 *
 * @param {boolean} refuse Whether setData throws
 * @return {object} The fake dataTransfer
 */
function dataTransfer(refuse = false) {
	return {
		data: {},
		effectAllowed: 'none',
		setData(type, value) {
			if (refuse) throw new Error('locked')
			this.data[type] = value
		},
	}
}

/**
 * Start a drag on an element.
 *
 * @param {HTMLElement} el The element
 * @param {boolean} refuse Whether setData throws
 * @return {object} The dataTransfer used
 */
function drag(el, refuse = false) {
	const event = new Event('dragstart')
	event.dataTransfer = dataTransfer(refuse)
	el.dispatchEvent(event)
	return event.dataTransfer
}

/**
 * An .attachment element.
 *
 * @param {object} extra Properties to put on the element
 * @return {HTMLElement} The element
 */
function attachment(extra = {}) {
	const el = document.createElement('div')
	el.className = 'attachment'
	Object.assign(el, extra)
	return el
}

/**
 * Mount a host that uses the composable.
 *
 * @return {object} The wrapper
 */
function host() {
	return mount(
		defineComponent({
			setup() {
				useAttachmentDrag()
				return () => h('span')
			},
		}),
	)
}

beforeEach(() => {
	document.body.innerHTML = ''
	window.history.replaceState(null, '', '/apps/mail/box/2/thread/77')
	global.MutationObserver = jest.fn((cb) => {
		observerCallback = cb
		return { observe: jest.fn(), disconnect: jest.fn() }
	})
})

describe('useAttachmentDrag edges', () => {
	it('reads Vue 3 props and defaults mime and size', () => {
		const el = attachment({
			__vueParentComponent: { props: { id: 'a b', fileName: 'x.txt' } },
		})
		document.body.appendChild(el)
		host()
		const dt = drag(el)
		expect(JSON.parse(dt.data[ATTACHMENT_MIME])).toEqual({
			messageId: 77,
			attachmentId: 'a b',
			fileName: 'x.txt',
			mime: 'application/octet-stream',
			size: 0,
			downloadUrl: '/apps/mail/api/messages/77/attachment/a%20b',
		})
		expect(dt.effectAllowed).toBe('copy')
		expect(dt.data['text/plain']).toBe('x.txt')
	})

	it('offers nothing for an element without usable props', () => {
		const el = attachment({
			__vueParentComponent: { props: { id: 'a' } },
			__vue__: { $props: { fileName: 'f' } },
		})
		document.body.appendChild(el)
		host()
		const dt = drag(el)
		expect(dt.data).toEqual({})
		expect(dt.effectAllowed).toBe('none')
	})

	it('offers nothing outside a thread', () => {
		window.history.replaceState(null, '', '/apps/mail/box/2')
		const el = attachment({ __vue__: { $props: { id: 'a', fileName: 'f' } } })
		document.body.appendChild(el)
		host()
		expect(drag(el).data).toEqual({})
	})

	it('warns instead of throwing when the drag data is refused', () => {
		const warn = jest.spyOn(console, 'warn').mockImplementation(() => {})
		const el = attachment({ __vue__: { $props: { id: 'a', fileName: 'f' } } })
		document.body.appendChild(el)
		host()
		drag(el, true)
		expect(warn).toHaveBeenCalledWith(
			'[openregister] Could not set drag data:',
			expect.any(Error),
		)
		warn.mockRestore()
	})

	it('patches added attachments, nested ones too, and skips text nodes', () => {
		host()
		const direct = attachment()
		const wrap = document.createElement('div')
		const nested = attachment()
		wrap.appendChild(nested)
		const text = document.createTextNode('hi')
		observerCallback([{ addedNodes: [text, direct, wrap] }])
		expect(direct.getAttribute('draggable')).toBe('true')
		expect(nested.getAttribute('draggable')).toBe('true')
		expect(wrap.getAttribute('draggable')).toBeNull()
	})

	it('patches an element only once', () => {
		host()
		const el = attachment()
		const add = jest.spyOn(el, 'addEventListener')
		observerCallback([{ addedNodes: [el] }])
		observerCallback([{ addedNodes: [el] }])
		expect(add).toHaveBeenCalledTimes(1)
	})
})
