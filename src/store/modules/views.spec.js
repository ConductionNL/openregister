/* eslint-disable no-console */
import { setActivePinia, createPinia } from 'pinia'

import { useViewsStore } from './views.js'

describe('Views Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
	})

	it('has correct initial state', () => {
		const store = useViewsStore()

		expect(store.item).toBeNull()
		expect(store.list).toEqual([])
		expect(store.filters).toEqual({})
		expect(store.pagination).toEqual({ page: 1, limit: 20 })
		expect(store.activeView).toBeNull()
	})

	it('has loading feature', () => {
		const store = useViewsStore()

		expect(store.loading).toBe(false)
		expect(store.error).toBeNull()
	})

	it('sets item correctly', () => {
		const store = useViewsStore()
		const view = { id: 1, name: 'My View', isPublic: true }

		store.setItem(view)

		expect(store.item).toEqual(view)
	})

	it('sets list correctly', () => {
		const store = useViewsStore()
		const views = [
			{ id: 1, name: 'View 1', isPublic: true },
			{ id: 2, name: 'View 2', isPublic: false },
		]

		store.setList(views)

		expect(store.list).toHaveLength(2)
	})

	it('sets and clears active view', () => {
		const store = useViewsStore()
		const view = { id: 1, name: 'Test View' }

		store.setActiveView(view)
		expect(store.activeView).toEqual(view)
		expect(store.getActiveView).toEqual(view)

		store.clearActiveView()
		expect(store.activeView).toBeNull()
	})

	describe('getters', () => {
		it('getAllViews returns list', () => {
			const store = useViewsStore()
			const views = [{ id: 1 }, { id: 2 }]

			store.setList(views)

			expect(store.getAllViews).toHaveLength(2)
		})

		it('getPublicViews filters public views', () => {
			const store = useViewsStore()

			store.setList([
				{ id: 1, isPublic: true },
				{ id: 2, isPublic: false },
				{ id: 3, isPublic: true },
			])

			expect(store.getPublicViews).toHaveLength(2)
		})

		it('getPrivateViews filters non-public views', () => {
			const store = useViewsStore()

			store.setList([
				{ id: 1, isPublic: true },
				{ id: 2, isPublic: false },
			])

			expect(store.getPrivateViews).toHaveLength(1)
			expect(store.getPrivateViews[0].id).toBe(2)
		})

		it('getDefaultView returns default view or null', () => {
			const store = useViewsStore()

			store.setList([
				{ id: 1, isDefault: false },
				{ id: 2, isDefault: true },
			])

			expect(store.getDefaultView.id).toBe(2)
		})

		it('getDefaultView returns null when no default', () => {
			const store = useViewsStore()

			store.setList([{ id: 1, isDefault: false }])

			expect(store.getDefaultView).toBeNull()
		})
	})

	describe('getOne', () => {
		it('unwraps { view: {...} } response', async () => {
			const store = useViewsStore()

			global.fetch = jest.fn().mockResolvedValue({
				ok: true,
				json: () => Promise.resolve({ view: { id: 1, name: 'Fetched View' } }),
			})

			const result = await store.getOne(1)

			expect(result).toEqual({ id: 1, name: 'Fetched View' })
		})

		it('handles flat response', async () => {
			const store = useViewsStore()

			global.fetch = jest.fn().mockResolvedValue({
				ok: true,
				json: () => Promise.resolve({ id: 1, name: 'Flat View' }),
			})

			const result = await store.getOne(1)

			expect(result).toEqual({ id: 1, name: 'Flat View' })
		})
	})

	describe('save', () => {
		it('POSTs for new views (no id/uuid)', async () => {
			const store = useViewsStore()
			store.setList([])

			global.fetch = jest.fn().mockResolvedValue({
				ok: true,
				json: () => Promise.resolve({ view: { id: 10, name: 'New View' } }),
			})

			const result = await store.save({ name: 'New View' })

			const [, options] = global.fetch.mock.calls[0]
			expect(options.method).toBe('POST')
			expect(result).toEqual({ id: 10, name: 'New View' })
			expect(store.list).toHaveLength(1)
		})

		it('PUTs for existing views (has id)', async () => {
			const store = useViewsStore()
			store.setList([{ id: 5, name: 'Old Name' }])

			global.fetch = jest.fn().mockResolvedValue({
				ok: true,
				json: () => Promise.resolve({ view: { id: 5, name: 'Updated' } }),
			})

			const result = await store.save({ id: 5, name: 'Updated' })

			const [, options] = global.fetch.mock.calls[0]
			expect(options.method).toBe('PUT')
			expect(result).toEqual({ id: 5, name: 'Updated' })
		})
	})

	describe('deleteOne', () => {
		it('removes view from list', async () => {
			const store = useViewsStore()
			store.setList([
				{ id: 1, name: 'View 1' },
				{ id: 2, name: 'View 2' },
			])

			global.fetch = jest.fn().mockResolvedValue({
				ok: true,
				json: () => Promise.resolve({}),
			})

			await store.deleteOne(1)

			expect(store.list).toHaveLength(1)
			expect(store.list[0].id).toBe(2)
		})

		it('clears active view if deleted', async () => {
			const store = useViewsStore()
			store.setList([{ id: 1, name: 'View 1' }])
			store.setActiveView({ id: 1, name: 'View 1' })

			global.fetch = jest.fn().mockResolvedValue({
				ok: true,
				json: () => Promise.resolve({}),
			})

			await store.deleteOne(1)

			expect(store.activeView).toBeNull()
		})

		it('accepts object with id', async () => {
			const store = useViewsStore()
			store.setList([{ id: 3, name: 'View 3' }])

			global.fetch = jest.fn().mockResolvedValue({
				ok: true,
				json: () => Promise.resolve({}),
			})

			await store.deleteOne({ id: 3 })

			expect(store.list).toHaveLength(0)
		})
	})

	describe('createViewFromSearchState', () => {
		it('builds view config from search store state', () => {
			const store = useViewsStore()
			const mockSearchStore = {
				selectedRegisters: ['reg-1'],
				selectedSchemas: ['schema-1'],
				source: 'internal',
				searchTerms: ['test'],
				facetFilters: { type: 'object' },
				enabledFacets: { type: true },
				advancedFilters: {},
				pagination: { page: 1, limit: 20 },
				sorting: { created: 'DESC' },
				columns: { name: true },
			}

			const view = store.createViewFromSearchState(mockSearchStore, 'My View', 'A test view', false, true)

			expect(view.name).toBe('My View')
			expect(view.description).toBe('A test view')
			expect(view.isDefault).toBe(false)
			expect(view.isPublic).toBe(true)
			expect(view.configuration.registers).toEqual(['reg-1'])
			expect(view.configuration.schemas).toEqual(['schema-1'])
			expect(view.configuration.source).toBe('internal')
		})
	})
})
