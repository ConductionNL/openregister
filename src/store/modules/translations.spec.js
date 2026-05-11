/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import axios from '@nextcloud/axios'
import {
	useTranslationsStore,
	isRtlLanguage,
	RTL_LANGUAGES,
	TRANSLATION_STATUSES,
} from './translations.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: {
		get: jest.fn(),
		post: jest.fn(),
	},
}))

jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `/index.php${path}`),
}))

describe('translations utilities', () => {
	describe('isRtlLanguage', () => {
		it('returns true for known RTL languages', () => {
			expect(isRtlLanguage('ar')).toBe(true)
			expect(isRtlLanguage('he')).toBe(true)
			expect(isRtlLanguage('fa')).toBe(true)
			expect(isRtlLanguage('ur')).toBe(true)
		})

		it('handles BCP 47 region tags', () => {
			expect(isRtlLanguage('ar-SA')).toBe(true)
			expect(isRtlLanguage('he-IL')).toBe(true)
		})

		it('returns false for LTR languages', () => {
			expect(isRtlLanguage('en')).toBe(false)
			expect(isRtlLanguage('nl')).toBe(false)
			expect(isRtlLanguage('de')).toBe(false)
		})

		it('handles edge cases', () => {
			expect(isRtlLanguage('')).toBe(false)
			expect(isRtlLanguage(null)).toBe(false)
			expect(isRtlLanguage(undefined)).toBe(false)
			expect(isRtlLanguage(42)).toBe(false)
		})

		it('is case insensitive', () => {
			expect(isRtlLanguage('AR')).toBe(true)
			expect(isRtlLanguage('He')).toBe(true)
		})
	})

	describe('RTL_LANGUAGES', () => {
		it('exposes a Set of language codes', () => {
			expect(RTL_LANGUAGES).toBeInstanceOf(Set)
			expect(RTL_LANGUAGES.has('ar')).toBe(true)
		})
	})

	describe('TRANSLATION_STATUSES', () => {
		it('mirrors backend status constants', () => {
			expect(TRANSLATION_STATUSES.DRAFT).toBe('draft')
			expect(TRANSLATION_STATUSES.MACHINE_TRANSLATED).toBe('machine_translated')
			expect(TRANSLATION_STATUSES.HUMAN_REVIEWED).toBe('human_reviewed')
			expect(TRANSLATION_STATUSES.APPROVED).toBe('approved')
		})

		it('is frozen', () => {
			expect(Object.isFrozen(TRANSLATION_STATUSES)).toBe(true)
		})
	})
})

describe('Translations Store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useTranslationsStore()
		jest.clearAllMocks()
	})

	describe('initial state', () => {
		it('starts empty and idle', () => {
			expect(store.byObject).toEqual({})
			expect(store.loading).toBe(false)
			expect(store.error).toBeNull()
		})
	})

	describe('getters', () => {
		it('returns empty arrays/objects for unknown uuids', () => {
			expect(store.getSlotsForObject('nope')).toEqual([])
			expect(store.getCompletenessForObject('nope')).toEqual({})
		})

		it('returns cached data for known uuids', () => {
			store.byObject = {
				abc: {
					translations: [{ property: 'title', language: 'nl', value: 'X' }],
					completeness: { nl: { translated: 1, total: 1, ratio: 1 } },
				},
			}
			expect(store.getSlotsForObject('abc')).toHaveLength(1)
			expect(store.getCompletenessForObject('abc').nl.ratio).toBe(1)
		})

		it('exposes loading + error via aliases', () => {
			store.loading = true
			store.error = 'boom'
			expect(store.isLoading).toBe(true)
			expect(store.getError).toBe('boom')
		})
	})

	describe('fetchByObject', () => {
		it('returns early without uuid', async () => {
			await store.fetchByObject(null, 'schema-1')
			expect(axios.get).not.toHaveBeenCalled()
		})

		it('fetches and caches translations + completeness', async () => {
			axios.get.mockResolvedValueOnce({
				data: {
					translations: [{ property: 'title', language: 'nl', value: 'Hallo' }],
					completeness: { nl: { translated: 1, total: 1, ratio: 1 } },
				},
			})

			await store.fetchByObject('uuid-1', 'schema-1')

			expect(axios.get).toHaveBeenCalledWith(
				expect.stringContaining('/object/uuid-1'),
				{ params: { schema: 'schema-1' } },
			)
			expect(store.byObject['uuid-1'].translations).toHaveLength(1)
			expect(store.byObject['uuid-1'].completeness.nl.ratio).toBe(1)
			expect(store.loading).toBe(false)
		})

		it('omits schema param when not provided', async () => {
			axios.get.mockResolvedValueOnce({ data: { translations: [], completeness: {} } })

			await store.fetchByObject('uuid-2')

			expect(axios.get).toHaveBeenCalledWith(
				expect.stringContaining('/object/uuid-2'),
				{ params: {} },
			)
		})

		it('records error on failure', async () => {
			axios.get.mockRejectedValueOnce(new Error('network down'))

			await expect(store.fetchByObject('uuid-3', 'schema-1')).rejects.toThrow('network down')
			expect(store.error).toBe('network down')
			expect(store.loading).toBe(false)
		})

		it('url-encodes the uuid', async () => {
			axios.get.mockResolvedValueOnce({ data: { translations: [], completeness: {} } })

			await store.fetchByObject('weird/uuid', 'schema-1')

			expect(axios.get).toHaveBeenCalledWith(
				expect.stringContaining('weird%2Fuuid'),
				expect.any(Object),
			)
		})
	})

	describe('setStatus', () => {
		it('rejects invalid statuses without calling the API', async () => {
			await expect(store.setStatus('uuid', 'title', 'nl', 'bogus')).rejects.toThrow(/Invalid translation status/)
			expect(axios.post).not.toHaveBeenCalled()
		})

		it('posts the status update and patches the cache in place', async () => {
			store.byObject = {
				'uuid-x': {
					translations: [
						{ property: 'title', language: 'nl', value: 'A', status: 'draft' },
						{ property: 'title', language: 'en', value: 'B', status: 'draft' },
					],
					completeness: {},
				},
			}
			axios.post.mockResolvedValueOnce({ data: {} })

			await store.setStatus('uuid-x', 'title', 'nl', TRANSLATION_STATUSES.APPROVED)

			expect(axios.post).toHaveBeenCalledWith(
				expect.stringContaining('/object/uuid-x/title/nl/status'),
				{ status: 'approved' },
			)
			expect(store.byObject['uuid-x'].translations[0].status).toBe('approved')
			expect(store.byObject['uuid-x'].translations[1].status).toBe('draft')
		})

		it('records the error message when the API call fails', async () => {
			axios.post.mockRejectedValueOnce(new Error('forbidden'))

			await expect(
				store.setStatus('uuid', 'title', 'nl', TRANSLATION_STATUSES.APPROVED),
			).rejects.toThrow('forbidden')
			expect(store.error).toBe('forbidden')
		})
	})

	describe('bulkTranslate', () => {
		it('posts the from/to/properties payload and returns response data', async () => {
			axios.post.mockResolvedValueOnce({
				data: {
					translated: { title: 'Hello' },
					skipped: { description: 'already_filled' },
				},
			})

			const result = await store.bulkTranslate('uuid', 'nl', 'en', ['title'])

			expect(axios.post).toHaveBeenCalledWith(
				expect.stringContaining('/object/uuid/bulk-translate'),
				{ from: 'nl', to: 'en', properties: ['title'] },
			)
			expect(result.translated.title).toBe('Hello')
			expect(result.skipped.description).toBe('already_filled')
		})

		it('records errors and rethrows', async () => {
			axios.post.mockRejectedValueOnce(new Error('provider down'))

			await expect(store.bulkTranslate('uuid', 'nl', 'en')).rejects.toThrow('provider down')
			expect(store.error).toBe('provider down')
		})
	})

	describe('search', () => {
		it('forwards params and returns response data', async () => {
			axios.get.mockResolvedValueOnce({ data: { results: [{ id: 1 }] } })

			const result = await store.search({ query: 'foo', language: 'nl' })

			expect(axios.get).toHaveBeenCalledWith(
				expect.stringContaining('/search'),
				{ params: { query: 'foo', language: 'nl' } },
			)
			expect(result.results).toHaveLength(1)
		})

		it('defaults to an empty params object', async () => {
			axios.get.mockResolvedValueOnce({ data: {} })

			await store.search()

			expect(axios.get).toHaveBeenCalledWith(
				expect.any(String),
				{ params: {} },
			)
		})
	})
})
