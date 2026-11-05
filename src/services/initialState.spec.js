/**
 * @spec exclude unit test of the initial-state copy helper
 */
import { freshState } from './initialState.js'

describe('freshState', () => {
	const STATE = {
		text: 'a',
		count: 0,
		on: false,
		none: null,
		unset: undefined,
		list: [{ id: 'x' }, 2],
		nested: { deeper: { values: [] } },
	}

	it('copies the state to an equal value', () => {
		expect(freshState(STATE)).toStrictEqual(STATE)
	})

	it('shares no object or array with the original', () => {
		const copy = freshState(STATE)
		expect(copy).not.toBe(STATE)
		expect(copy.list).not.toBe(STATE.list)
		expect(copy.list[0]).not.toBe(STATE.list[0])
		expect(copy.nested.deeper).not.toBe(STATE.nested.deeper)
		expect(copy.nested.deeper.values).not.toBe(STATE.nested.deeper.values)
	})

	it('keeps keys whose value is undefined', () => {
		expect(Object.keys(freshState(STATE))).toContain('unset')
	})

	it('returns primitives as they are', () => {
		expect(freshState('s')).toBe('s')
		expect(freshState(null)).toBeNull()
	})
})
