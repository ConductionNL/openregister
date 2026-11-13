import SearchTrailIndex from './SearchTrailIndex.vue'
/**
 * The search trail page's own logic: the header and row menus, filter and
 * selection state, the details text, rerunning a search, the store and
 * dialog calls, and the display helpers.
 *
 * @spec exclude Unit test of client-side search trail page logic.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import eventBus from '../../eventBus.js'
import { navigationStore, searchTrailStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		vars ? text.replace(/{(\w+)}/g, (m, k) => (k in vars ? vars[k] : m)) : text,
	translatePlural: (app, s) => s,
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setDialog: jest.fn() },
	searchTrailStore: {
		searchTrailFilters: {},
		searchTrailList: [],
		refreshSearchTrailList: jest.fn(),
		setSearchTrailFilters: jest.fn(),
		setSearchTrailItem: jest.fn(),
	},
}))
jest.mock('../../eventBus.js', () => ({ emit: jest.fn(), on: jest.fn(), off: jest.fn() }))
jest.mock('../../components/PaginationComponent.vue', () => ({}))
jest.mock('../../components/shared/ActionMenu.vue', () => ({}))
jest.mock('../../components/shared/ListActionsBar.vue', () => ({}))
jest.mock('vue-material-design-icons/Broom.vue', () => ({ name: 'Broom' }))
jest.mock('vue-material-design-icons/Cog.vue', () => ({ name: 'Cog' }))
jest.mock('vue-material-design-icons/Delete.vue', () => ({ name: 'Delete' }))
jest.mock('vue-material-design-icons/Eye.vue', () => ({ name: 'Eye' }))
jest.mock('vue-material-design-icons/MagnifyPlus.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({ name: 'Refresh' }))

const { methods, computed } = SearchTrailIndex

/**
 * A bare page context with the page's methods bound to it.
 *
 * @param {object} extra Extra state
 * @return {object} The context
 */
function page(extra = {}) {
	const ctx = {
		...SearchTrailIndex.data.call({}),
		t: (app, text, vars) =>
			vars ? text.replace(/{(\w+)}/g, (m, k) => (k in vars ? vars[k] : m)) : text,
		$router: { push: jest.fn() },
		...extra,
	}
	for (const [name, method] of Object.entries(methods)) {
		ctx[name] = typeof method === 'function' ? method.bind(ctx) : method
	}
	return ctx
}

let consoleError

beforeEach(() => {
	jest.clearAllMocks()
	searchTrailStore.searchTrailFilters = {}
	searchTrailStore.searchTrailList = []
	global.OC = {
		Notification: { showTemporary: jest.fn() },
		dialogs: { info: jest.fn() },
	}
	consoleError = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	consoleError.mockRestore()
	delete global.OC
})

describe('headerActions', () => {
	it('hides bulk delete without a selection and runs each entry', () => {
		const ctx = page()
		ctx.bulkDeleteSearchTrails = jest.fn()
		ctx.cleanupSearchTrails = jest.fn()
		ctx.refreshSearchTrails = jest.fn()

		const entries = computed.headerActions.call(ctx)
		expect(entries.map((e) => e.label)).toEqual(['Delete (0)', 'Cleanup Old Trails', 'Refresh'])
		expect(entries[0].hidden).toBe(true)

		entries.forEach((e) => e.run())
		expect(ctx.bulkDeleteSearchTrails).toHaveBeenCalledTimes(1)
		expect(ctx.cleanupSearchTrails).toHaveBeenCalledTimes(1)
		expect(ctx.refreshSearchTrails).toHaveBeenCalledTimes(1)
	})

	it('counts the selection in the bulk delete label', () => {
		const entries = computed.headerActions.call(page({ selectedSearchTrails: [1, 2] }))
		expect(entries[0].label).toBe('Delete (2)')
		expect(entries[0].hidden).toBe(false)
	})
})

describe('filter and selection state', () => {
	it.each([
		[{}, false],
		[{ register: null, schema: undefined, user: '' }, false],
		[{ register: null, schema: 0 }, true],
		[{ user: 'admin' }, true],
	])('filters %j are active: %s', (filters, expected) => {
		searchTrailStore.searchTrailFilters = filters
		expect(computed.hasActiveFilters.call({})).toBe(expected)
	})

	it('treats missing filters as none', () => {
		searchTrailStore.searchTrailFilters = null
		expect(computed.hasActiveFilters.call({})).toBe(false)
	})

	it('passes the list through only when it is an array', () => {
		const list = [{ id: 1 }]
		searchTrailStore.searchTrailList = list
		expect(computed.paginatedSearchTrails.call({})).toBe(list)
		searchTrailStore.searchTrailList = 'nope'
		expect(computed.paginatedSearchTrails.call({})).toEqual([])
	})

	it.each([
		[[], [], false, false],
		[[{ id: 1 }, { id: 2 }], [], false, false],
		[[{ id: 1 }, { id: 2 }], [1], false, true],
		[[{ id: 1 }, { id: 2 }], [2, 1], true, false],
	])('rows %j with selection %j: all %s, some %s', (rows, selected, all, some) => {
		const ctx = { paginatedSearchTrails: rows, selectedSearchTrails: selected }
		ctx.allSelected = computed.allSelected.call(ctx)
		expect(ctx.allSelected).toBe(all)
		expect(computed.someSelected.call(ctx)).toBe(some)
	})

	it('selects every row and clears the selection', () => {
		const ctx = page({ paginatedSearchTrails: [{ id: 4 }, { id: 5 }] })
		ctx.toggleSelectAll(true)
		expect(ctx.selectedSearchTrails).toEqual([4, 5])
		ctx.toggleSelectAll(false)
		expect(ctx.selectedSearchTrails).toEqual([])
	})

	it('adds and removes a single row', () => {
		const ctx = page({ selectedSearchTrails: [1] })
		ctx.toggleSearchTrailSelection(2, true)
		expect(ctx.selectedSearchTrails).toEqual([1, 2])
		ctx.toggleSearchTrailSelection(1, false)
		expect(ctx.selectedSearchTrails).toEqual([2])
	})
})

describe('searchTrailActions', () => {
	it('offers every entry for a trail with a term and parameters', () => {
		const ctx = page()
		ctx.viewDetails = jest.fn()
		ctx.viewParameters = jest.fn()
		ctx.rerunSearch = jest.fn()
		ctx.deleteSearchTrail = jest.fn()
		const trail = { id: 1, searchTerm: 'tax', parameters: { a: 1 } }

		const entries = methods.searchTrailActions.call(ctx, trail)
		expect(entries.map((e) => [e.label, Boolean(e.hidden)])).toEqual([
			['View Details', false],
			['View Parameters', false],
			['Rerun Search', false],
			['Delete', false],
		])
		expect(entries[3].class).toBe('deleteAction')

		entries.forEach((e) => e.run())
		expect(ctx.viewDetails).toHaveBeenCalledWith(trail)
		expect(ctx.viewParameters).toHaveBeenCalledWith(trail)
		expect(ctx.rerunSearch).toHaveBeenCalledWith(trail)
		expect(ctx.deleteSearchTrail).toHaveBeenCalledWith(trail)
	})

	it('hides parameters and rerun when the trail has neither', () => {
		const entries = page().searchTrailActions({ id: 1 })
		expect(entries[1].hidden).toBe(true)
		expect(entries[2].hidden).toBe(true)
	})
})

describe('store and dialog calls', () => {
	it('loads the list and reports a failure as an error notice', async () => {
		await page().loadSearchTrails()
		expect(searchTrailStore.refreshSearchTrailList).toHaveBeenCalledTimes(1)
		expect(OC.Notification.showTemporary).not.toHaveBeenCalled()

		searchTrailStore.refreshSearchTrailList.mockRejectedValueOnce(new Error('down'))
		await page().loadSearchTrails()
		expect(OC.Notification.showTemporary).toHaveBeenCalledWith('Error loading search trails', { type: 'error' })
	})

	it('stores new filters and reloads', () => {
		const ctx = page()
		ctx.loadSearchTrails = jest.fn()
		methods.handleFiltersChanged.call(ctx, { user: 'admin' })
		expect(searchTrailStore.setSearchTrailFilters).toHaveBeenCalledWith({ user: 'admin' })
		expect(ctx.loadSearchTrails).toHaveBeenCalledTimes(1)
	})

	it('refreshes by reloading', async () => {
		const ctx = page()
		ctx.loadSearchTrails = jest.fn()
		await methods.refreshSearchTrails.call(ctx)
		expect(ctx.loadSearchTrails).toHaveBeenCalledTimes(1)
	})

	it.each([
		['viewParameters', 'searchTrailParameters'],
		['deleteSearchTrail', 'deleteSearchTrail'],
	])('%s selects the trail and opens %s', (method, dialog) => {
		const trail = { id: 3 }
		page()[method](trail)
		expect(searchTrailStore.setSearchTrailItem).toHaveBeenCalledWith(trail)
		expect(navigationStore.setDialog).toHaveBeenCalledWith(dialog)
	})

	it('emits the row count for the sidebar, zero for a non-list', () => {
		searchTrailStore.searchTrailList = [{}, {}]
		page().updateCounts()
		searchTrailStore.searchTrailList = undefined
		page().updateCounts()
		expect(eventBus.emit.mock.calls).toEqual([
			['search-trail-filtered-count', 2],
			['search-trail-filtered-count', 0],
		])
	})
})

describe('viewDetails', () => {
	it('lists every field and the parameters', () => {
		const trail = {
			searchTerm: 'tax',
			created: '2026-03-04T05:06:07Z',
			registerName: 'Reg',
			register: 1,
			schemaName: 'Sch',
			userName: 'Ann',
			totalResults: 12,
			responseTime: 1500,
			parameters: { page: 2 },
		}
		page().viewDetails(trail)

		expect(OC.dialogs.info).toHaveBeenCalledWith(
			[
				'Search Term: tax',
				`Timestamp: ${new Date(trail.created).toLocaleString()}`,
				'Register: Reg',
				'Schema: Sch',
				'User: Ann',
				'Results: 12',
				'Execution Time: 1.50s',
				`Parameters: ${JSON.stringify({ page: 2 }, null, 2)}`,
			].join('\n'),
			'Search Trail Details',
			null,
			true,
		)
	})

	it('falls back to ids, N/A and zero, and leaves out absent parameters', () => {
		page().viewDetails({ register: 1, schema: 2, user: 'u1', created: '2026-03-04T05:06:07Z' })
		const lines = OC.dialogs.info.mock.calls[0][0].split('\n')
		expect(lines).toEqual([
			'Search Term: N/A',
			expect.stringMatching(/^Timestamp: /),
			'Register: 1',
			'Schema: 2',
			'User: u1',
			'Results: 0',
			'Execution Time: -',
		])

		page().viewDetails({ parameters: 'raw' })
		const other = OC.dialogs.info.mock.calls[1][0].split('\n')
		expect(other.slice(2, 5)).toEqual(['Register: N/A', 'Schema: N/A', 'User: N/A'])
		expect(other.some((l) => l.startsWith('Parameters'))).toBe(false)
	})
})

describe('rerunSearch', () => {
	it('opens the search page with the term, register, schema and parameters', () => {
		const ctx = page()
		ctx.rerunSearch({ searchTerm: 'tax', register: 1, schema: 2, parameters: '{"page":3,"schema":9}' })
		expect(ctx.$router.push).toHaveBeenCalledWith({
			name: 'search',
			query: { q: 'tax', register: 1, schema: 9, page: 3 },
		})
		expect(OC.Notification.showTemporary).toHaveBeenCalledWith('Rerunning search: tax', { type: 'info' })
	})

	it('merges object parameters and ignores unreadable ones', () => {
		const ctx = page()
		ctx.rerunSearch({ searchTerm: 'a', register: 1, schema: 2, parameters: { limit: 5 } })
		ctx.rerunSearch({ searchTerm: 'b', register: 1, schema: 2, parameters: '{broken' })
		expect(ctx.$router.push.mock.calls).toEqual([
			[{ name: 'search', query: { q: 'a', register: 1, schema: 2, limit: 5 } }],
			[{ name: 'search', query: { q: 'b', register: 1, schema: 2 } }],
		])
	})
})

describe('display helpers', () => {
	it.each([
		[null, false],
		[{}, false],
		[{ parameters: '{}' }, false],
		[{ parameters: '{"a":1}' }, true],
		[{ parameters: '{bad' }, false],
		[{ parameters: {} }, false],
		[{ parameters: { a: 1 } }, true],
		[{ parameters: 5 }, false],
	])('%j has parameters: %s', (trail, expected) => {
		expect(methods.hasParameters(trail)).toBe(expected)
	})

	it.each([
		[0, '-'],
		[undefined, '-'],
		[250, '250ms'],
		[999, '999ms'],
		[1000, '1.00s'],
		[12345, '12.35s'],
	])('formats %s as %s', (ms, expected) => {
		expect(methods.formatExecutionTime(ms)).toBe(expected)
	})
})
