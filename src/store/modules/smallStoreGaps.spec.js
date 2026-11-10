/**
 * The remaining getters, setters, refusals and failure paths of the
 * navigation, views, quality, search, search-trail, source, translations and
 * webhook stores.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { useNavigationStore } from './navigation.js'
import { useQualityStore } from './quality.js'
import { useSearchStore } from './search.ts'
import { useSearchTrailStore } from './searchTrail.js'
import { useSourceStore } from './source.js'
import { useTranslationsStore } from './translations.js'
import { useViewsStore } from './views.js'
import { useWebhookStore } from './webhook.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn(), put: jest.fn(), delete: jest.fn() },
}))

jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `/index.php${path}`),
}))

let errSpy
beforeEach(() => {
	setActivePinia(createPinia())
	jest.clearAllMocks()
	errSpy = jest.spyOn(console, 'error').mockImplementation(() => {})
})
afterEach(() => errSpy.mockRestore())

describe('navigation store', () => {
	it('toggles a sidebar and keeps, returns and clears transfer data', () => {
		const store = useNavigationStore()
		store.setSidebarState('chat', false)
		expect(store.sidebarState.chat).toBe(false)
		expect(store.sidebarState.logs).toBe(true)
		store.setTransferData({ id: 7 })
		expect(store.getTransferData()).toEqual({ id: 7 })
		store.clearTransferData()
		expect(store.getTransferData()).toBeNull()
	})
})

describe('views store getters', () => {
	it('splits public and private views and finds the default', () => {
		const store = useViewsStore()
		store.viewsList = [
			{ id: 1, isPublic: true },
			{ id: 2, isPublic: false, isDefault: true },
			{ id: 3 },
		]
		expect(store.getAllViews).toHaveLength(3)
		expect(store.getPublicViews.map((v) => v.id)).toEqual([1])
		expect(store.getPrivateViews.map((v) => v.id)).toEqual([2, 3])
		expect(store.getDefaultView.id).toBe(2)
		store.viewsList = [{ id: 4 }]
		expect(store.getDefaultView).toBeNull()
	})

	it('sets and clears the active view and exposes loading and error', () => {
		const store = useViewsStore()
		store.setActiveView({ id: 9 })
		expect(store.getActiveView).toEqual({ id: 9 })
		store.clearActiveView()
		expect(store.getActiveView).toBeNull()
		store.loading = true
		store.error = 'bad'
		expect(store.isLoading).toBe(true)
		expect(store.getError).toBe('bad')
	})
})

describe('quality store', () => {
	it('exposes loading and error and clears the error', () => {
		const store = useQualityStore()
		store.loading = true
		store.error = 'x'
		expect(store.isLoading).toBe(true)
		expect(store.getError).toBe('x')
		store.clearError()
		expect(store.getError).toBeNull()
	})

	it('fetchWebhookHealth keeps a null stat for a webhook whose stats fail', async () => {
		axios.get.mockImplementation(async (url) => {
			if (url.endsWith('/webhooks')) return { data: { results: [{ id: 'ok' }, { id: 'bad' }] } }
			if (url.includes('/webhooks/bad/')) throw new Error('500')
			if (url.includes('/webhooks/ok/')) return { data: { delivered: 2 } }
			return { data: { results: [{ id: 'f1' }] } }
		})
		const store = useQualityStore()
		const result = await store.fetchWebhookHealth()
		expect(result.stats).toEqual({ ok: { delivered: 2 }, bad: null })
		expect(store.webhookStats.bad).toBeNull()
		expect(errSpy).toHaveBeenCalledWith('[quality.fetchWebhookHealth] per-webhook stats failed', expect.objectContaining({ webhook: { id: 'bad' } }))
	})
})

describe('search store', () => {
	it('sets the search term and results', () => {
		const store = useSearchStore()
		store.setSearch('zoek')
		store.setSearchResults('res')
		expect(store.search).toBe('zoek')
		expect(store.searchResults).toBe('res')
		store.clearSearch()
		expect(store.search).toBe('')
	})
})

describe('search trail store', () => {
	it('sets the search term', () => {
		const store = useSearchTrailStore()
		store.setSearchTrailSearch('term')
		expect(store.searchTrailSearch).toBe('term')
	})
})

describe('source store', () => {
	it('refuses to delete or save without a source', async () => {
		global.fetch = jest.fn()
		const store = useSourceStore()
		await expect(store.deleteSource({})).rejects.toThrow('No source item to delete')
		await expect(store.saveSource(null)).rejects.toThrow('No source item to save')
		expect(global.fetch).not.toHaveBeenCalled()
		delete global.fetch
	})
})

describe('translations store', () => {
	it('search records, logs and rethrows a failure', async () => {
		axios.get.mockRejectedValue(new Error('timeout'))
		const store = useTranslationsStore()
		await expect(store.search({ q: 'a' })).rejects.toThrow('timeout')
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/translations/search', { params: { q: 'a' } })
		expect(store.error).toBe('timeout')
		expect(store.loading).toBe(false)
		expect(errSpy).toHaveBeenCalledWith('[translations.search]', expect.any(Error))
	})

	it('search falls back to a fixed message when the error has none', async () => {
		axios.get.mockRejectedValue({})
		const store = useTranslationsStore()
		await expect(store.search()).rejects.toEqual({})
		expect(store.error).toBe('Failed to search translations')
	})
})

describe('webhook store getters', () => {
	it('exposes the item, loading and error', () => {
		const store = useWebhookStore()
		store.webhookItem = { id: 1 }
		store.loading = true
		store.error = 'e'
		expect(store.getWebhookItem).toEqual({ id: 1 })
		expect(store.isLoading).toBe(true)
		expect(store.getError).toBe('e')
	})
})
