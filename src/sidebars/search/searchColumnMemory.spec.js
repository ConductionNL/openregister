/**
 * The columns picked on the records page are kept per schema and come back.
 *
 * @spec openspec/changes/records-api-and-table-stated/specs/zoeken-filteren/spec.md#REQ-RAT-002
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { rememberedSearchColumns, rememberSearchColumns } from './searchColumnMemory.js'

describe('searchColumnMemory', () => {
	beforeEach(() => {
		window.localStorage.clear()
		jest.restoreAllMocks()
	})

	it('gives back the columns picked for a schema', () => {
		rememberSearchColumns(7, ['prioriteit', 'aangemaakt'])

		expect(rememberedSearchColumns(7)).toEqual(['prioriteit', 'aangemaakt'])
		expect(rememberedSearchColumns('7')).toEqual(['prioriteit', 'aangemaakt'])
	})

	it('keeps each schema to its own choice', () => {
		rememberSearchColumns(7, ['prioriteit'])
		rememberSearchColumns(8, ['omschrijving'])

		expect(rememberedSearchColumns(7)).toEqual(['prioriteit'])
		expect(rememberedSearchColumns(8)).toEqual(['omschrijving'])
	})

	it('forgets the choice when the list is emptied', () => {
		rememberSearchColumns(7, ['prioriteit'])
		rememberSearchColumns(7, [])

		expect(rememberedSearchColumns(7)).toEqual([])
		expect(window.localStorage.getItem('openregister:search-columns:7')).toBeNull()
	})

	it('remembers nothing without a schema', () => {
		rememberSearchColumns(null, ['prioriteit'])

		expect(window.localStorage.length).toBe(0)
		expect(rememberedSearchColumns(null)).toEqual([])
		expect(rememberedSearchColumns('')).toEqual([])
	})

	it('answers nothing remembered for a corrupt or foreign value', () => {
		window.localStorage.setItem('openregister:search-columns:7', '{not json')
		expect(rememberedSearchColumns(7)).toEqual([])

		window.localStorage.setItem('openregister:search-columns:7', JSON.stringify({ a: 1 }))
		expect(rememberedSearchColumns(7)).toEqual([])

		window.localStorage.setItem('openregister:search-columns:7', JSON.stringify(['ok', 3]))
		expect(rememberedSearchColumns(7)).toEqual(['ok'])
	})

	it('survives storage that refuses to be used', () => {
		jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('blocked') })
		jest.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('blocked') })

		expect(() => rememberSearchColumns(7, ['prioriteit'])).not.toThrow()
		expect(rememberedSearchColumns(7)).toEqual([])
	})
})
