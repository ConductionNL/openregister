/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The app-wide event bus that replaced Vue 2's $on/$off/$emit.
 *
 * @spec exclude Unit test of the client-side event bus.
 */
import bus, { emit, off, on } from './eventBus.js'

afterEach(() => {
	off('t1')
	off('t2')
	jest.restoreAllMocks()
})

describe('eventBus', () => {
	it('calls every handler of an event with the payload, in order', () => {
		const calls = []
		on('t1', (p) => calls.push(['a', p]))
		on('t1', (p) => calls.push(['b', p]))
		on('t2', () => calls.push(['other']))
		emit('t1', 42)
		expect(calls).toEqual([['a', 42], ['b', 42]])
	})

	it('refuses a handler that is not a function, and warns', () => {
		const warn = jest.spyOn(console, 'warn').mockImplementation(() => {})
		on('t1', undefined)
		expect(warn).toHaveBeenCalledWith(expect.stringContaining('refusing to subscribe "t1"'))
		expect(() => emit('t1')).not.toThrow()
	})

	it('removes one handler, or all of an event', () => {
		const a = jest.fn()
		const b = jest.fn()
		on('t1', a)
		on('t1', b)
		off('t1', a)
		emit('t1', 1)
		expect(a).not.toHaveBeenCalled()
		expect(b).toHaveBeenCalledWith(1)
		off('t1', b)
		off('t1', b)
		emit('t1', 2)
		expect(b).toHaveBeenCalledTimes(1)
		on('t2', a)
		off('t2')
		emit('t2')
		expect(a).not.toHaveBeenCalled()
	})

	it('keeps calling the other handlers when one throws', () => {
		const error = jest.spyOn(console, 'error').mockImplementation(() => {})
		const after = jest.fn()
		on('t1', () => {
			throw new Error('bad')
		})
		on('t1', after)
		emit('t1', 'p')
		expect(after).toHaveBeenCalledWith('p')
		expect(error).toHaveBeenCalledWith(
			'[openregister/eventBus] handler for "t1" threw',
			expect.any(Error),
		)
	})

	it('lets a handler unsubscribe itself while being called', () => {
		const second = jest.fn()
		const first = () => off('t1', first)
		on('t1', first)
		on('t1', second)
		emit('t1')
		emit('t1')
		expect(second).toHaveBeenCalledTimes(2)
	})

	it('exports the same functions as its default', () => {
		const handler = jest.fn()
		bus.on('t1', handler)
		bus.emit('t1', 'x')
		expect(handler).toHaveBeenCalledWith('x')
	})
})
