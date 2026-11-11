/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Parsing the Mail app URL and following it as the user navigates.
 *
 * @spec exclude Unit test of the client-side mail URL observer.
 */
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { parseMailUrl, useMailObserver } from './useMailObserver.js'

describe('parseMailUrl', () => {
	it.each([
		['/apps/mail/box/priority/thread/6', 1, 6],
		['/apps/mail/box/2/thread/42', 1, 42],
		['/apps/mail/box/3', 3, null],
		['#/accounts/4/folders/INBOX/messages/9', 4, 9],
		['#/accounts/5/folders/INBOX/', 5, null],
		['/apps/files/', null, null],
		['', null, null],
		[null, null, null],
	])('reads %s as account %s, message %s', (url, accountId, messageId) => {
		expect(parseMailUrl(url)).toEqual({ accountId, messageId, sender: null })
	})
})

/**
 * Mount a component that uses the observer.
 *
 * @param {object} options The observer options
 * @return {{wrapper: object, state: object}} The wrapper and observer state
 */
function observe(options) {
	let state
	const Host = defineComponent({
		setup() {
			state = useMailObserver(options)
			return () => h('div')
		},
	})
	const wrapper = mount(Host)
	return { wrapper, state }
}

describe('useMailObserver', () => {
	beforeEach(() => {
		jest.useFakeTimers()
		window.history.replaceState(null, '', '/apps/mail/box/2/thread/42')
	})
	afterEach(() => {
		jest.useRealTimers()
		window.history.replaceState(null, '', '/')
	})

	it('parses the URL on mount', () => {
		const { state } = observe()
		expect(state.accountId.value).toBe(1)
		expect(state.messageId.value).toBe(42)
		expect(state.isMessageView.value).toBe(true)
	})

	it('picks up a pushState navigation on the next poll, debounced', () => {
		const onChange = jest.fn()
		const { state } = observe({ onChange, debounceMs: 100 })
		window.history.pushState(null, '', '/apps/mail/box/7')
		jest.advanceTimersByTime(500)
		expect(onChange).not.toHaveBeenCalled()
		jest.advanceTimersByTime(100)
		expect(onChange).toHaveBeenCalledWith({
			accountId: 7,
			messageId: null,
			sender: null,
		})
		expect(state.accountId.value).toBe(7)
		expect(state.isMessageView.value).toBe(false)
	})

	it('prefers the hash over the path and reacts to hashchange', () => {
		const onChange = jest.fn()
		const { state } = observe({ onChange })
		window.history.replaceState(null, '', '/x#/accounts/3/folders/INBOX/messages/8')
		window.dispatchEvent(new Event('hashchange'))
		jest.advanceTimersByTime(300)
		expect(state.messageId.value).toBe(8)
		expect(onChange).toHaveBeenCalledTimes(1)
	})

	it('does not call back when the URL changes but the ids do not', () => {
		const onChange = jest.fn()
		observe({ onChange })
		window.history.pushState(null, '', '/apps/mail/box/starred/thread/42')
		window.dispatchEvent(new Event('popstate'))
		jest.advanceTimersByTime(300)
		expect(onChange).not.toHaveBeenCalled()
	})

	it('restarts the debounce when the URL changes again', () => {
		const onChange = jest.fn()
		observe({ onChange })
		window.history.pushState(null, '', '/apps/mail/box/7')
		window.dispatchEvent(new Event('popstate'))
		jest.advanceTimersByTime(200)
		window.history.pushState(null, '', '/apps/mail/box/8')
		window.dispatchEvent(new Event('popstate'))
		jest.advanceTimersByTime(300)
		expect(onChange).toHaveBeenCalledTimes(1)
		expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ accountId: 8 }))
	})

	it('stops listening and polling once unmounted', () => {
		const onChange = jest.fn()
		const { wrapper } = observe({ onChange })
		window.history.pushState(null, '', '/apps/mail/box/7')
		window.dispatchEvent(new Event('popstate'))
		wrapper.unmount()
		jest.advanceTimersByTime(2000)
		window.dispatchEvent(new Event('popstate'))
		jest.advanceTimersByTime(2000)
		expect(onChange).not.toHaveBeenCalled()
	})
})
