/**
 * The debounced option search waits for the typing to stop, then fills the
 * options with the answer and the selected items it misses.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude Unit test of select-option search plumbing.
 */
import { debouncedOptionSearch, searchUrl } from './optionSearch.js'

const FIELDS = {
	timer: 'timer',
	loading: 'loading',
	options: 'options',
	selected: 'selected',
	label: 'things',
}

beforeEach(() => {
	jest.useFakeTimers()
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	jest.useRealTimers()
	jest.restoreAllMocks()
})

describe('searchUrl', () => {
	it('encodes the query and limits to 10', () => {
		expect(searchUrl('schemas', 'a b&c')).toBe(
			'/index.php/apps/openregister/api/schemas?_search=a%20b%26c&_limit=10',
		)
	})
})

describe('debouncedOptionSearch', () => {
	it('runs only the last search, 300 ms after it', async () => {
		const vm = { timer: null, loading: false, options: [], selected: [] }
		const url = jest.fn(() => '/u')
		global.fetch = jest.fn(async () => ({ json: async () => [] }))
		debouncedOptionSearch(vm, { ...FIELDS, url: () => 'first' })
		debouncedOptionSearch(vm, { ...FIELDS, url })
		jest.advanceTimersByTime(299)
		expect(global.fetch).not.toHaveBeenCalled()
		jest.advanceTimersByTime(1)
		expect(vm.loading).toBe(true)
		await jest.runAllTimersAsync()
		expect(global.fetch.mock.calls).toEqual([['/u']])
		expect(vm.loading).toBe(false)
	})

	it('puts the selected items the answer misses in front', async () => {
		const vm = {
			timer: null,
			loading: false,
			options: [],
			selected: [{ id: 2 }, { id: 9 }],
		}
		global.fetch = jest.fn(async () => ({
			json: async () => ({ results: [{ id: 1 }, { id: 2 }] }),
		}))
		debouncedOptionSearch(vm, { ...FIELDS, url: () => '/u' })
		await jest.runAllTimersAsync()
		expect(vm.options).toEqual([{ id: 9 }, { id: 1 }, { id: 2 }])
	})

	it('empties the options and logs when the search fails', async () => {
		const vm = { timer: null, loading: false, options: [{ id: 1 }], selected: [] }
		global.fetch = jest.fn(() => Promise.reject(new Error('down')))
		debouncedOptionSearch(vm, { ...FIELDS, url: () => '/u' })
		await jest.runAllTimersAsync()
		expect(vm.options).toEqual([])
		expect(vm.loading).toBe(false)
		expect(console.error.mock.calls[0][0]).toBe('Error searching things:')
	})
})
